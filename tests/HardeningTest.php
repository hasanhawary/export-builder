<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Covers the hardening fixes: storage disk resolution, unique stored paths,
 * temp-file cleanup, safe download / url, class resolution and request validation.
 */
class HardeningTest extends TestCase
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
        $app['config']->set('export.module.routes.middleware', []);
        $app['config']->set('export.module.permissions.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hardening_people', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('export_files', function (Blueprint $table): void {
            $table->id();
            $table->string('exportable_type');
            $table->unsignedBigInteger('exportable_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('disk')->nullable();
            $table->string('format')->default('xlsx');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        HardeningPerson::create(['name' => 'Hasan']);
    }

    private function queuedExport(array $filters, ?int $owner = 1): ExportFile
    {
        return ExportFile::create([
            'exportable_type' => $filters['page'],
            'format' => $filters['format'] ?? 'xlsx',
            'status' => ExportStatus::Pending->value,
            'disk' => 'exports-test',
            'metadata' => ['filters' => $filters],
            'created_by' => $owner,
        ]);
    }

    // ---------------------------------------------------------------------
    // Storage disk
    // ---------------------------------------------------------------------

    public function test_storage_disk_follows_the_app_default_disk_when_not_set(): void
    {
        config()->set('export.module.storage.disk', null);
        config()->set('filesystems.default', 's3');

        $this->assertSame('s3', app(ExportFileService::class)->storageDisk());
    }

    public function test_configured_storage_disk_wins_over_the_app_default(): void
    {
        config()->set('export.module.storage.disk', 'exports-disk');
        config()->set('filesystems.default', 's3');

        $this->assertSame('exports-disk', app(ExportFileService::class)->storageDisk());
    }

    public function test_record_disk_wins_over_config_for_existing_files(): void
    {
        config()->set('export.module.storage.disk', 'new-disk');
        $export = new ExportFile(['disk' => 'old-disk']);

        $this->assertSame('old-disk', app(ExportFileService::class)->diskFor($export));
    }

    // ---------------------------------------------------------------------
    // Queued job
    // ---------------------------------------------------------------------

    public function test_two_exports_of_the_same_page_in_the_same_minute_do_not_overwrite_each_other(): void
    {
        Storage::fake('exports-test');
        $filters = ['page' => 'hardening_person', 'format' => 'csv', 'timestamp' => '20260101_1200'];

        $first  = $this->queuedExport($filters, 1);
        $second = $this->queuedExport($filters, 2);

        (new ExportToFile($first->id))->handle(app(ExportFileService::class));
        (new ExportToFile($second->id))->handle(app(ExportFileService::class));

        $first->refresh();
        $second->refresh();

        $this->assertSame(ExportStatus::Completed->value, $first->status);
        $this->assertSame(ExportStatus::Completed->value, $second->status);
        $this->assertNotSame($first->file_path, $second->file_path);
        $this->assertSame($first->file_name, $second->file_name);
        Storage::disk('exports-test')->assertExists($first->file_path);
        Storage::disk('exports-test')->assertExists($second->file_path);
        $this->assertStringContainsString('Hasan', Storage::disk('exports-test')->get($first->file_path));
    }

    public function test_job_removes_its_temporary_file(): void
    {
        Storage::fake('exports-test');
        $export = $this->queuedExport(['page' => 'hardening_person', 'format' => 'xlsx']);

        $before = $this->tempFiles();
        (new ExportToFile($export->id))->handle(app(ExportFileService::class));

        $this->assertSame(ExportStatus::Completed->value, $export->refresh()->status);
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    public function test_failed_job_is_marked_failed_and_rethrows(): void
    {
        Storage::fake('exports-test');
        $export = $this->queuedExport(['page' => 'does_not_exist']);

        try {
            (new ExportToFile($export->id))->handle(app(ExportFileService::class));
            $this->fail('The job should rethrow.');
        } catch (HttpException) {
            // expected
        }

        $this->assertSame(ExportStatus::Failed->value, $export->refresh()->status);
        $this->assertNull($export->file_path);
    }

    public function test_job_uses_the_configured_queue(): void
    {
        config()->set('export.module.queue.connection', 'redis');
        config()->set('export.module.queue.name', 'exports');

        $job = new ExportToFile(1);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('exports', $job->queue);
    }

    public function test_job_uses_the_app_default_queue_when_not_configured(): void
    {
        $job = new ExportToFile(1);

        $this->assertNull($job->connection);
        $this->assertNull($job->queue);
    }

    // ---------------------------------------------------------------------
    // Download / url
    // ---------------------------------------------------------------------

    public function test_download_of_a_file_missing_from_disk_is_404_not_500(): void
    {
        Storage::fake('exports-test');
        $export = $this->queuedExport(['page' => 'hardening_person']);
        $export->update(['file_path' => 'exports/gone.xlsx', 'file_name' => 'gone.xlsx', 'status' => 'completed']);

        $this->actingAs(new HardeningUser(1))
            ->get("/api/export-log/{$export->id}/download")
            ->assertNotFound();
    }

    public function test_download_streams_an_existing_file(): void
    {
        Storage::fake('exports-test');
        Storage::disk('exports-test')->put('exports/here.csv', 'id,name');
        $export = $this->queuedExport(['page' => 'hardening_person']);
        $export->update(['file_path' => 'exports/here.csv', 'file_name' => 'here.csv', 'status' => 'completed']);

        $this->actingAs(new HardeningUser(1))
            ->get("/api/export-log/{$export->id}/download")
            ->assertOk()
            ->assertDownload('here.csv');
    }

    public function test_url_is_null_when_the_disk_cannot_build_urls(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('url')->andThrow(new RuntimeException('This driver does not support retrieving URLs.'));
        Storage::set('no-urls', $disk);

        $export = new ExportFile(['disk' => 'no-urls', 'file_path' => 'exports/a.xlsx']);

        $this->assertNull($export->url());
    }

    public function test_history_listing_still_works_when_the_disk_cannot_build_urls(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('url')->andThrow(new RuntimeException('no urls'));
        Storage::set('no-urls', $disk);

        $export = $this->queuedExport(['page' => 'hardening_person']);
        $export->update(['disk' => 'no-urls', 'file_path' => 'exports/a.xlsx', 'status' => 'completed']);

        $this->actingAs(new HardeningUser(1))
            ->getJson('/api/export-log?per_page=-1')
            ->assertOk()
            ->assertJsonPath('data.0.file_url', null);
    }

    // ---------------------------------------------------------------------
    // PDF temp file
    // ---------------------------------------------------------------------

    public function test_pdf_temp_file_is_deleted_after_sending_and_nothing_is_left_behind(): void
    {
        $before = $this->tempFiles();

        $response = (new ExportBuilder(['page' => 'hardening_person', 'format' => 'pdf']))->response();
        $path = $response->getFile()->getRealPath();

        $this->assertSame('%PDF-', file_get_contents($path, false, null, 0, 5));

        ob_start();
        $response->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], array_values(array_diff($this->tempFiles(), $before)));
    }

    // ---------------------------------------------------------------------
    // Class resolution / validation / translations
    // ---------------------------------------------------------------------

    public function test_page_that_resolves_to_a_non_export_class_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        try {
            (new ExportBuilder(['page' => 'not_an', 'format' => 'xlsx']))->response();
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertFalse(NotAnExport::$constructed);
            throw $e;
        }
    }

    public function test_nested_arrays_in_columns_are_rejected_with_422(): void
    {
        $this->actingAs(new HardeningUser(1))
            ->getJson('/api/export-direct?page=hardening_person&columns[0][]=name')
            ->assertStatus(422)
            ->assertJsonValidationErrors('columns.0');
    }

    public function test_string_columns_are_accepted(): void
    {
        Queue::fake();

        $this->actingAs(new HardeningUser(1))
            ->postJson('/api/export', ['page' => 'hardening_person', 'columns' => ['name'], 'related' => ['x']])
            ->assertStatus(202);

        Queue::assertPushed(ExportToFile::class);
    }

    public function test_resolve_trans_with_a_language_does_not_change_the_app_locale(): void
    {
        app()->setLocale('en');

        ExportHelper::resolveTrans('completed', 'export', 'ar');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_resolve_trans_uses_the_requested_language(): void
    {
        app('translator')->addLines(['export.hardening_status' => 'جاهز'], 'ar', 'export');
        app('translator')->addLines(['export.hardening_status' => 'Ready'], 'en', 'export');
        app()->setLocale('en');

        $this->assertSame('جاهز', ExportHelper::resolveTrans('hardening_status', 'export', 'ar'));
        $this->assertSame('Ready', ExportHelper::resolveTrans('hardening_status', 'export'));
    }

    /** @return list<string> */
    private function tempFiles(): array
    {
        clearstatcache();

        return array_merge(
            glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eb_pdf_*') ?: [],
            glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-excel-*') ?: [],
            glob(storage_path('framework/cache/laravel-excel') . DIRECTORY_SEPARATOR . '*') ?: [],
        );
    }
}

class HardeningPerson extends Model
{
    protected $table = 'hardening_people';

    protected $guarded = [];
}

class HardeningPersonExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => HardeningPerson::class,
            'columns' => ['id' => 'int', 'name' => 'text'],
        ], $filter);
    }
}

/** Lives in the export namespace and ends in "Export", but is not an export. */
class NotAnExport
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}

class HardeningUser extends Authenticatable
{
    public function __construct(private int $userId = 1)
    {
        parent::__construct();
        $this->setAttribute('id', $userId);
    }

    public function getAuthIdentifier(): int
    {
        return $this->userId;
    }
}
