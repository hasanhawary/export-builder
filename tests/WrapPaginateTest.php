<?php

namespace HasanHawary\ExportBuilder\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Orchestra\Testbench\TestCase;

class WrapPaginateTest extends TestCase
{
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
        $body = $result->toResponse($request)->getData(true);

        $this->assertSame([['identifier' => 1]], $body['data']);
        $this->assertSame(3, $body['meta']['total']);
        $this->assertArrayHasKey('next', $body['links']);
        $this->assertSame('Records', $body['label']);
    }
}

class WrapPaginateResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['identifier' => $this->resource['id']];
    }
}
