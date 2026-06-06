<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Http\Controllers\ExportController;
use HasanHawary\ExportBuilder\Http\Controllers\ExportJobController;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportService;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;

class ExportRoutesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ExcelServiceProvider::class,
            LaravelMpdfServiceProvider::class,
            ExportBuilderServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.module.routes.enabled', false);
    }

    public function test_fresh_project_registers_package_export_routes(): void
    {
        $this->enablePackageRoutes();

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('export-builder.export.direct');
        $downloadRoute = Route::getRoutes()->getByName('export-builder.export.download');
        $storeRoute = Route::getRoutes()->getByName('export-builder.export.store');
        $logsRoute = Route::getRoutes()->getByName('export-builder.export.logs.index');
        $downloadFileRoute = Route::getRoutes()->getByName('export-builder.export.logs.download');

        $this->assertNotNull($route);
        $this->assertNotNull($downloadRoute);
        $this->assertNotNull($storeRoute);
        $this->assertNotNull($logsRoute);
        $this->assertNotNull($downloadFileRoute);
        $this->assertSame('api/export-direct', $route->uri());
        $this->assertSame('api/export', $downloadRoute->uri());
        $this->assertSame('api/export', $storeRoute->uri());
        $this->assertSame('api/export-log', $logsRoute->uri());
        $this->assertSame('api/export-log/{exportFile}/download', $downloadFileRoute->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('POST', $storeRoute->methods());
        $this->assertSame(ExportController::class, $route->getActionName());
    }

    public function test_existing_project_export_route_wins_on_conflict(): void
    {
        Route::get('api/export', fn () => 'host')->name('host.export');

        $this->enablePackageRoutes();

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('export-builder.export.direct'));
        $this->assertFalse(Route::has('export-builder.export.download'));
        $this->assertSame('host.export', Route::getRoutes()->getByName('host.export')->getName());
    }

    public function test_package_export_routes_can_be_disabled(): void
    {
        config()->set('export.module.routes.enabled', false);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->assertFalse(Route::has('export-builder.export.direct'));
        $this->assertFalse(Route::has('export-builder.export.download'));
        $this->assertFalse(Route::has('export-builder.export.store'));
    }

    public function test_package_export_module_can_be_disabled(): void
    {
        config()->set('export.module.enabled', false);
        config()->set('export.module.routes.enabled', true);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->assertFalse(Route::has('export-builder.export.direct'));
        $this->assertFalse(Route::has('export-builder.export.download'));
        $this->assertFalse(Route::has('export-builder.export.store'));
    }

    public function test_package_export_route_prefix_and_name_prefix_can_be_customized(): void
    {
        $this->enablePackageRoutes([
            'prefix' => 'internal/reports/export',
            'direct_path' => 'direct',
            'export_path' => 'download',
            'name_prefix' => 'tenant.exports.',
        ]);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('tenant.exports.direct');

        $this->assertNotNull($route);
        $this->assertSame('internal/reports/export/direct', $route->uri());
        $this->assertSame('internal/reports/export/download', Route::getRoutes()->getByName('tenant.exports.download')->uri());
        $this->assertFalse(Route::has('export-builder.export.direct'));
    }

    public function test_export_controller_and_service_can_be_overridden(): void
    {
        $this->enablePackageRoutes([
            'prefix' => 'custom-export',
            'direct_path' => '',
            'middleware' => [],
        ]);
        config()->set('export.module.controllers.direct', TestExportController::class);
        config()->set('export.module.services.export', TestExportService::class);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->getJson('/custom-export')
            ->assertOk()
            ->assertJson(['controller' => 'custom']);

        $this->assertInstanceOf(TestExportService::class, app(ExportService::class));
    }

    public function test_post_export_route_creates_export_file_and_dispatches_job(): void
    {
        $this->migrateExportFilesTable();
        Queue::fake();
        $this->enablePackageRoutes(['middleware' => []]);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->postJson('/api/export', [
            'page' => 'user',
            'format' => 'xlsx',
        ])->assertAccepted()
            ->assertJsonPath('data.exportable_type', 'user')
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('data.status', 'pending');

        $export = ExportFile::first();

        $this->assertNotNull($export);
        $this->assertSame('user', $export->exportable_type);
        $this->assertSame('xlsx', $export->format);
        $this->assertSame('pending', $export->status);
        Queue::assertPushed(ExportToFile::class, fn (ExportToFile $job) => $job->exportId === $export->id);
    }

    public function test_permission_denies_direct_and_queued_exports(): void
    {
        $this->migrateExportFilesTable();
        $this->enablePackageRoutes(['middleware' => []]);
        config()->set('export.module.permissions.enabled', true);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs(new TestUser([]))
            ->getJson('/api/export-direct?page=user&format=xlsx')
            ->assertForbidden();

        $this->actingAs(new TestUser([]))
            ->postJson('/api/export', ['page' => 'user', 'format' => 'xlsx'])
            ->assertForbidden();
    }

    public function test_permission_allows_configured_direct_and_queued_exports(): void
    {
        $this->migrateExportFilesTable();
        Queue::fake();
        $this->enablePackageRoutes(['middleware' => []]);
        config()->set('export.module.permissions.enabled', true);
        config()->set('export.module.permissions.pages.route_test_user.export', 'export-user');
        config()->set('export.module.permissions.pages.user.queue', 'queue-user-export');
        config()->set('export.namespace', __NAMESPACE__);
        Schema::create('route_test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        RouteTestUser::create(['name' => 'Allowed']);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs(new TestUser(['export-user']))
            ->get('/api/export-direct?page=route_test_user&format=xlsx')
            ->assertOk();

        $this->actingAs(new TestUser(['queue-user-export']))
            ->postJson('/api/export', ['page' => 'user', 'format' => 'xlsx'])
            ->assertAccepted();
    }

    public function test_export_file_download_requires_owner_or_view_all_permission(): void
    {
        $this->migrateExportFilesTable();
        Storage::fake('local');
        $this->enablePackageRoutes(['middleware' => []]);
        config()->set('export.module.permissions.enabled', true);

        $export = ExportFile::create([
            'exportable_type' => 'user',
            'created_by' => 10,
            'file_name' => 'users.xlsx',
            'file_path' => 'exports/users.xlsx',
            'disk' => 'local',
            'format' => 'xlsx',
            'status' => 'completed',
            'metadata' => [],
        ]);
        Storage::disk('local')->put('exports/users.xlsx', 'content');

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs(new TestUser(['view-own-export-file'], 20))
            ->get("/api/export-log/{$export->id}/download")
            ->assertForbidden();

        $this->actingAs(new TestUser(['view-own-export-file'], 10))
            ->get("/api/export-log/{$export->id}/download")
            ->assertOk();

        $this->actingAs(new TestUser(['view-all-export-file'], 20))
            ->get("/api/export-log/{$export->id}/download")
            ->assertOk();
    }

    public function test_delete_export_log_soft_deletes_the_record(): void
    {
        $this->migrateExportFilesTable();
        $this->enablePackageRoutes(['middleware' => []]);
        config()->set('export.module.permissions.enabled', false);

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $export = ExportFile::create([
            'exportable_type' => 'user',
            'created_by'      => 1,
            'file_name'       => 'users.xlsx',
            'file_path'       => null,
            'disk'            => 'local',
            'format'          => 'xlsx',
            'status'          => 'completed',
            'metadata'        => [],
        ]);

        $this->deleteJson("/api/export-log/{$export->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Export deleted successfully.');

        $this->assertSoftDeleted('export_files', ['id' => $export->id]);
    }

    public function test_delete_export_log_route_is_registered(): void
    {
        $this->enablePackageRoutes();

        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('export-builder.export.logs.destroy');

        $this->assertNotNull($route);
        $this->assertSame('api/export-log/{exportFile}', $route->uri());
        $this->assertContains('DELETE', $route->methods());
    }

    private function enablePackageRoutes(array $routeConfig = []): void
    {
        config()->set('export.module.enabled', true);
        config()->set('export.module.routes.enabled', true);
        config()->set('export.module.routes.prefix', 'api');
        config()->set('export.module.routes.export_path', 'export');
        config()->set('export.module.routes.direct_path', 'export-direct');
        config()->set('export.module.routes.log_path', 'export-log');
        config()->set('export.module.routes.name_prefix', 'export-builder.export.');
        config()->set('export.module.controllers.jobs', ExportJobController::class);

        foreach ($routeConfig as $key => $value) {
            config()->set("export.module.routes.{$key}", $value);
        }
    }

    private function migrateExportFilesTable(): void
    {
        Schema::dropIfExists('export_files');

        $migration = require __DIR__.'/../database/migrations/create_export_files_table.php';
        $migration->up();
    }
}

class TestExportController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['controller' => 'custom']);
    }
}

class TestExportService extends ExportService
{
}

class TestUser extends Authenticatable
{
    public function __construct(private array $abilities = [], private int $id = 1)
    {
        $this->setAttribute('id', $id);
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function can($abilities, $arguments = []): bool
    {
        return $this->hasPermissionTo($abilities);
    }

    public function hasPermissionTo($abilities, $guardName = null): bool
    {
        foreach ((array) $abilities as $ability) {
            if (in_array($ability, $this->abilities, true)) {
                return true;
            }
        }

        return false;
    }
}

class RouteTestUser extends Model
{
    protected $table = 'route_test_users';

    protected $fillable = ['name'];
}

class RouteTestUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => RouteTestUser::class,
            'columns' => [
                'id' => 'int',
                'name' => 'text',
            ],
            'relations' => [
                'one' => [],
                'many' => [
                    'concat' => [],
                    'list' => [],
                    'count' => [],
                ],
            ],
        ], $filter);
    }
}
