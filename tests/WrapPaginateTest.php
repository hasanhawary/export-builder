<?php

namespace HasanHawary\ExportBuilder\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Orchestra\Testbench\TestCase;

class WrapPaginateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\HasanHawary\ExportBuilder\ExportBuilderServiceProvider::class];
    }

    public function test_generic_helpers_are_not_loaded_through_composer_files(): void
    {
        // Composer "files" run before the app's own helper files: anything defined there
        // would replace the app's wrapPaginate()/successResponse() (the 2.4.4 regression).
        $files = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true)['autoload']['files'] ?? [];
        $this->assertSame(['src/helpers.php'], $files);

        $helpers = file_get_contents(__DIR__ . '/../src/helpers.php');
        $this->assertStringNotContainsString('function wrapPaginate', $helpers);
        $this->assertStringNotContainsString('function successResponse', $helpers);

        $this->assertTrue(function_exists('wrapPaginate'), 'fallback is loaded by the service provider');
        $this->assertTrue(function_exists('successResponse'));
    }

    public function test_an_app_helper_defined_before_the_provider_wins(): void
    {
        // Simulates the real order in a fresh PHP process: app helper first, then the package.
        $script = sprintf(
            "function wrapPaginate(\$q = null, \$r = null, \$m = []) { return 'app'; } "
            . 'require %s; require %s; echo wrapPaginate();',
            var_export(realpath(__DIR__ . '/../vendor/autoload.php'), true),
            var_export(realpath(__DIR__ . '/../src/fallback-helpers.php'), true),
        );

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));

        $this->assertSame('app', trim((string) $output));
    }

    public function test_invalid_page_sizes_use_the_default(): void
    {
        config()->set('project.pagination.per_page', 15);

        foreach ([null, 'abc', '0', '-2', '1.5', ['10']] as $value) {
            $this->app->instance('request', Request::create('/', 'GET', ['per_page' => $value]));
            $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate', 'get'])->getMock();
            $query->expects($this->once())->method('paginate')->with(15)->willReturn(new LengthAwarePaginator([], 0, 15));
            $query->expects($this->never())->method('get');

            $this->assertInstanceOf(LengthAwarePaginator::class, wrapPaginate($query));
        }
    }

    public function test_minus_one_fetches_all_and_metadata_cannot_replace_data(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', ['per_page' => '-1']));
        $items = collect([['id' => 1]]);
        $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate', 'get'])->getMock();
        $query->expects($this->never())->method('paginate');
        $query->expects($this->once())->method('get')->willReturn($items);

        $result = wrapPaginate($query, null, ['data' => 'overwrite', 'label' => 'Records']);

        $this->assertSame($items, $result['data']);
        $this->assertSame('Records', $result['label']);
    }

    public function test_resource_response_preserves_pagination_and_metadata(): void
    {
        $request = Request::create('/', 'GET', ['per_page' => '2']);
        $this->app->instance('request', $request);
        $paginator = new LengthAwarePaginator(collect([['id' => 1]]), 3, 2);
        $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate'])->getMock();
        $query->expects($this->once())->method('paginate')->with(2)->willReturn($paginator);

        $result = wrapPaginate($query, WrapPaginateResource::class, ['data' => 'overwrite', 'label' => 'Records']);

        // Same shape as before (paginator under "data"), but items go through the resource.
        $this->assertSame('Records', $result['label']);
        $this->assertSame($paginator, $result['data']);

        $body = json_decode(json_encode($result['data']), true);
        $this->assertSame([['identifier' => 1]], $body['data']);
        $this->assertSame(3, $body['total']);
        $this->assertSame(2, $body['per_page']);
    }

    public function test_resource_is_applied_when_paginating_without_metadata(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', ['per_page' => '1']));
        $paginator = new LengthAwarePaginator(collect([['id' => 7]]), 1, 1);
        $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate'])->getMock();
        $query->expects($this->once())->method('paginate')->with(1)->willReturn($paginator);

        $result = wrapPaginate($query, WrapPaginateResource::class);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertSame([['identifier' => 7]], json_decode(json_encode($result), true)['data']);
    }

    public function test_without_page_size_config_missing_per_page_returns_everything(): void
    {
        config()->set('project.pagination.per_page', null);
        $this->app->instance('request', Request::create('/', 'GET'));
        $items = collect([['id' => 1]]);
        $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate', 'get'])->getMock();
        $query->expects($this->never())->method('paginate');
        $query->expects($this->once())->method('get')->willReturn($items);

        $this->assertSame($items, wrapPaginate($query));
    }
}

class WrapPaginateResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['identifier' => $this->resource['id']];
    }
}
