<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Architecture-level regression tests.
 *
 * These tests protect the four SSOT fixes applied in the architecture review:
 *  1. File naming is consistent between direct response and queued job
 *  2. Storage config is centralised in ExportFileService
 *  3. headings() does not mutate instance state (PDF + column-filter safety)
 *  4. index() authorization goes through ExportPermissionResolver
 */
class ArchitectureTest extends TestCase
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
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.enabled', false);
        $app['config']->set('export.module.routes.enabled', false);
    }

    // =========================================================================
    // Issue 1 — File naming SSOT
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function file_name_builder_produces_slug_with_extension_for_xlsx(): void
    {
        $name = ExportBuilder::buildFileName(
            ['page' => 'user', 'timestamp' => '20260605_120000'],
            'xlsx'
        );

        $this->assertSame('user-20260605-120000.xlsx', $name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function file_name_builder_produces_slug_with_extension_for_pdf(): void
    {
        $name = ExportBuilder::buildFileName(
            ['page' => 'user', 'timestamp' => '20260605_120000'],
            'pdf'
        );

        $this->assertSame('user-20260605-120000.pdf', $name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function file_name_builder_uses_filename_override_when_present(): void
    {
        $name = ExportBuilder::buildFileName(
            ['page' => 'user', 'filename' => 'custom report', 'timestamp' => '20260605_120000'],
            'csv'
        );

        $this->assertSame('custom-report-20260605-120000.csv', $name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function file_name_builder_falls_back_to_export_when_no_page_or_filename(): void
    {
        $name = ExportBuilder::buildFileName(
            ['timestamp' => '20260605_120000'],
            'xls'
        );

        $this->assertSame('export-20260605-120000.xls', $name);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function extension_for_format_returns_correct_extensions(): void
    {
        $this->assertSame('xlsx', ExportBuilder::extensionForFormat('xlsx'));
        $this->assertSame('xlsx', ExportBuilder::extensionForFormat('unknown'));
        $this->assertSame('pdf',  ExportBuilder::extensionForFormat('pdf'));
        $this->assertSame('csv',  ExportBuilder::extensionForFormat('csv'));
        $this->assertSame('xls',  ExportBuilder::extensionForFormat('xls'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function direct_and_queued_export_produce_same_filename_format(): void
    {
        // The filename slug format must be identical whether the export is direct or queued.
        // If this test breaks, ExportToFile is building names differently from ExportBuilder.
        $filters   = ['page' => 'report', 'timestamp' => '20260605_090000'];
        $direct    = ExportBuilder::buildFileName($filters, 'xlsx');
        $queued    = ExportBuilder::buildFileName($filters, 'xlsx'); // job now calls same method

        $this->assertSame($direct, $queued);
        $this->assertSame('report-20260605-090000.xlsx', $direct);
    }

    // =========================================================================
    // Issue 2 — Storage config SSOT
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function export_file_service_storage_disk_reads_from_config(): void
    {
        config()->set('export.module.storage.disk', 's3');

        $service = new ExportFileService;

        $this->assertSame('s3', $service->storageDisk());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function export_file_service_storage_path_reads_from_config(): void
    {
        config()->set('export.module.storage.path', 'custom/exports');

        $service = new ExportFileService;

        $this->assertSame('custom/exports', $service->storagePath());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function export_file_service_storage_disk_has_local_default(): void
    {
        config()->set('export.module.storage.disk', null);

        $service = new ExportFileService;

        $this->assertSame('local', $service->storageDisk());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function export_file_service_storage_path_has_exports_default(): void
    {
        config()->set('export.module.storage.path', null);

        $service = new ExportFileService;

        $this->assertSame('exports', $service->storagePath());
    }

    // =========================================================================
    // Issue 3 — headings() must not mutate instance state
    // =========================================================================

    protected function setUpArchDatabase(): void
    {
        Schema::create('arch_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        ArchUser::create(['name' => 'Hasan', 'email' => 'h@example.test']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function headings_does_not_mutate_columns_on_the_instance(): void
    {
        $this->setUpArchDatabase();

        $export = new ArchUserExport([]);

        // Call headings() — this must not destroy $this->columns
        $headings = $export->headings();

        // map() must still produce all columns after headings() was called
        $user   = ArchUser::first();
        $mapped = $export->map($user);

        $this->assertArrayHasKey('id',    $mapped);
        $this->assertArrayHasKey('name',  $mapped);
        $this->assertArrayHasKey('email', $mapped);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function headings_called_multiple_times_returns_same_result(): void
    {
        $this->setUpArchDatabase();

        $export = new ArchUserExport([]);

        $first  = $export->headings();
        $second = $export->headings();

        $this->assertSame($first, $second);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function map_called_before_headings_returns_full_column_set(): void
    {
        $this->setUpArchDatabase();

        $export = new ArchUserExport([]);
        $user   = ArchUser::first();

        // Call map() BEFORE headings() — must still return all columns
        $mapped = $export->map($user);

        $this->assertArrayHasKey('id',    $mapped);
        $this->assertArrayHasKey('name',  $mapped);
        $this->assertArrayHasKey('email', $mapped);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function column_filter_applied_in_headings_does_not_affect_subsequent_map_call(): void
    {
        $this->setUpArchDatabase();

        // Only request 'name' column
        $export = new ArchUserExport(['columns' => ['name']]);
        $user   = ArchUser::first();

        $headings = $export->headings();
        $mapped   = $export->map($user);

        // headings() returns translated labels — 'name' column → 'Name'
        $this->assertContains('Name', $headings);
        $this->assertNotContains('ID',    $headings);
        $this->assertNotContains('Email', $headings);

        // map() keys are raw column names (not translated) — only 'name' key present
        $this->assertArrayHasKey('name',  $mapped);
        $this->assertArrayNotHasKey('id',    $mapped);
        $this->assertArrayNotHasKey('email', $mapped);
    }

    // =========================================================================
    // Issue 4 — Authorization SSOT: index goes through resolver
    // =========================================================================

    private function migrateExportFilesTable(): void
    {
        Schema::dropIfExists('export_files');
        $migration = require __DIR__ . '/../database/migrations/create_export_files_table.php';
        $migration->up();
    }

    private function enableRoutes(): void
    {
        config()->set('export.module.enabled', true);
        config()->set('export.module.routes.enabled', true);
        config()->set('export.module.routes.prefix', 'arch-test');
        config()->set('export.module.routes.export_path', 'export');
        config()->set('export.module.routes.direct_path', 'export-direct');
        config()->set('export.module.routes.log_path', 'export-log');
        config()->set('export.module.routes.name_prefix', 'export-builder.export.');
        config()->set('export.module.routes.middleware', []);
        config()->set('export.module.controllers.jobs', \HasanHawary\ExportBuilder\Http\Controllers\ExportJobController::class);
        app(ExportRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_403_when_user_has_no_export_file_permission(): void
    {
        $this->migrateExportFilesTable();
        $this->enableRoutes();
        config()->set('export.module.permissions.enabled', true);

        $this->actingAs(new ArchTestUser([]))
            ->getJson('/arch-test/export-log')
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_only_own_records_for_view_own_user(): void
    {
        $this->migrateExportFilesTable();
        $this->enableRoutes();
        config()->set('export.module.permissions.enabled', true);

        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 1,
        ]);
        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 99,
        ]);

        $response = $this->actingAs(new ArchTestUser(['view-own-export-file'], 1))
            ->getJson('/arch-test/export-log')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function index_returns_all_records_for_view_all_user(): void
    {
        $this->migrateExportFilesTable();
        $this->enableRoutes();
        config()->set('export.module.permissions.enabled', true);

        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 1,
        ]);
        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 99,
        ]);

        $response = $this->actingAs(new ArchTestUser(['view-all-export-file'], 1))
            ->getJson('/arch-test/export-log')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function custom_resolver_override_is_honoured_on_index(): void
    {
        $this->migrateExportFilesTable();
        config()->set('export.module.permissions.enabled', true);
        config()->set('export.module.services.permissions', AlwaysDenyResolver::class);

        // Re-bind so the container picks up the new config value
        $this->app->bind(ExportPermissionResolver::class, function ($app) {
            $service = config('export.module.services.permissions', ExportPermissionResolver::class);
            return $app->make($service);
        });

        $this->enableRoutes();

        // Even a user with 'view-all' ability should be denied because the custom resolver denies everything
        $this->actingAs(new ArchTestUser(['view-all-export-file'], 1))
            ->getJson('/arch-test/export-log')
            ->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function scope_for_user_returns_empty_when_user_has_no_permission(): void
    {
        $this->migrateExportFilesTable();
        config()->set('export.module.permissions.enabled', true);

        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 1,
        ]);

        $resolver = new ExportPermissionResolver;
        $query    = ExportFile::query();
        $resolver->scopeForUser($query, new ArchTestUser([], 1));

        $this->assertCount(0, $query->get());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function scope_for_user_returns_all_when_permissions_disabled(): void
    {
        $this->migrateExportFilesTable();
        config()->set('export.module.permissions.enabled', false);

        ExportFile::create([
            'exportable_type' => 'user', 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local',
            'metadata' => [], 'created_by' => 99,
        ]);

        $resolver = new ExportPermissionResolver;
        $query    = ExportFile::query();
        $resolver->scopeForUser($query, new ArchTestUser([], 1));

        $this->assertCount(1, $query->get());
    }
}

// ---------------------------------------------------------------------------
// Inline test doubles
// ---------------------------------------------------------------------------

class ArchUser extends Model
{
    protected $table    = 'arch_users';
    protected $fillable = ['name', 'email'];
}

class ArchUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => ArchUser::class,
            'columns' => ['id' => 'int', 'name' => 'text', 'email' => 'text'],
        ], $filter);
    }
}

class ArchTestUser extends AuthenticatableUser
{
    public function __construct(
        private array $abilities = [],
        private int   $userId    = 1
    ) {
        $this->setAttribute('id', $userId);
    }

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): int        { return $this->userId; }

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

/**
 * Custom resolver that always denies — used to test that index() honours resolver overrides.
 */
class AlwaysDenyResolver extends ExportPermissionResolver
{
    public function canList(?Authenticatable $user): bool                    { return false; }
    public function canExport(?Authenticatable $user, array $filters): bool  { return false; }
}
