<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

/**
 * Tests for ExportFileService lifecycle methods and storage helpers.
 *
 * Covers: create, markAsProcessing, markAsCompleted, markAsFailed, delete,
 * storageDisk, storagePath, and edge cases.
 */
class ExportFileServiceTest extends TestCase
{
    private ExportFileService $service;

    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('export_files');
        $migration = require __DIR__ . '/../database/migrations/create_export_files_table.php';
        $migration->up();

        $this->service = new ExportFileService;
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function test_create_stores_exportable_type_from_page_filter(): void
    {
        $export = $this->service->create(['page' => 'user', 'format' => 'xlsx']);

        $this->assertSame('user', $export->exportable_type);
    }

    public function test_create_stores_format_from_filter(): void
    {
        $export = $this->service->create(['page' => 'user', 'format' => 'pdf']);

        $this->assertSame('pdf', $export->format);
    }

    public function test_create_defaults_format_to_xlsx_when_missing(): void
    {
        $export = $this->service->create(['page' => 'user']);

        $this->assertSame('xlsx', $export->format);
    }

    public function test_create_sets_status_to_pending(): void
    {
        $export = $this->service->create(['page' => 'user', 'format' => 'xlsx']);

        $this->assertSame(ExportStatus::Pending->value, $export->status);
    }

    public function test_create_stores_disk_from_service(): void
    {
        config()->set('export.module.storage.disk', 's3');

        $export = $this->service->create(['page' => 'user', 'format' => 'xlsx']);

        $this->assertSame('s3', $export->disk);
    }

    public function test_create_stores_full_filters_in_metadata(): void
    {
        $filters = ['page' => 'user', 'format' => 'xlsx', 'start' => '2026-01-01'];
        $export  = $this->service->create($filters);

        $this->assertSame($filters, $export->metadata['filters']);
    }

    // =========================================================================
    // markAsProcessing()
    // =========================================================================

    public function test_mark_as_processing_sets_status_and_started_at(): void
    {
        $export = $this->makeExport();

        $this->service->markAsProcessing($export);
        $export->refresh();

        $this->assertSame(ExportStatus::Processing->value, $export->status);
        $this->assertNotNull($export->started_at);
    }

    // =========================================================================
    // markAsCompleted()
    // =========================================================================

    public function test_mark_as_completed_sets_all_fields(): void
    {
        $export = $this->makeExport();

        $this->service->markAsCompleted($export, 'exports/file.xlsx', 'file.xlsx');
        $export->refresh();

        $this->assertSame(ExportStatus::Completed->value, $export->status);
        $this->assertSame('exports/file.xlsx', $export->file_path);
        $this->assertSame('file.xlsx', $export->file_name);
        $this->assertNotNull($export->completed_at);
        $this->assertNull($export->error_message);
    }

    public function test_mark_as_completed_clears_previous_error_message(): void
    {
        $export = $this->makeExport(['error_message' => 'previous error']);

        $this->service->markAsCompleted($export, 'exports/file.xlsx', 'file.xlsx');
        $export->refresh();

        $this->assertNull($export->error_message);
    }

    // =========================================================================
    // markAsFailed()
    // =========================================================================

    public function test_mark_as_failed_sets_status_error_and_completed_at(): void
    {
        $export = $this->makeExport();

        $this->service->markAsFailed($export, 'Something went wrong');
        $export->refresh();

        $this->assertSame(ExportStatus::Failed->value, $export->status);
        $this->assertSame('Something went wrong', $export->error_message);
        $this->assertNotNull($export->completed_at);
    }

    // =========================================================================
    // delete()
    // =========================================================================

    public function test_delete_soft_deletes_the_record(): void
    {
        $export = $this->makeExport();

        $this->service->delete($export);

        $this->assertSoftDeleted('export_files', ['id' => $export->id]);
    }

    public function test_delete_removes_file_from_storage_when_file_exists(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/test.xlsx', 'content');

        $export = $this->makeExport(['file_path' => 'exports/test.xlsx', 'disk' => 'local']);

        $this->service->delete($export);

        Storage::disk('local')->assertMissing('exports/test.xlsx');
    }

    public function test_delete_does_not_crash_when_file_path_is_null(): void
    {
        $export = $this->makeExport(['file_path' => null]);

        // Must not throw
        $result = $this->service->delete($export);

        $this->assertTrue($result);
        $this->assertSoftDeleted('export_files', ['id' => $export->id]);
    }

    public function test_delete_does_not_crash_when_file_does_not_exist_on_disk(): void
    {
        Storage::fake('local');
        // File NOT put on disk — but record has a file_path

        $export = $this->makeExport(['file_path' => 'exports/missing.xlsx', 'disk' => 'local']);

        // Must not throw
        $result = $this->service->delete($export);

        $this->assertTrue($result);
    }

    public function test_delete_uses_service_disk_when_record_disk_is_null(): void
    {
        Storage::fake('local');
        config()->set('export.module.storage.disk', 'local');
        Storage::disk('local')->put('exports/file.xlsx', 'data');

        $export = $this->makeExport(['file_path' => 'exports/file.xlsx', 'disk' => null]);

        $this->service->delete($export);

        Storage::disk('local')->assertMissing('exports/file.xlsx');
    }

    // =========================================================================
    // storageDisk() / storagePath()
    // =========================================================================

    public function test_storage_disk_returns_configured_value(): void
    {
        config()->set('export.module.storage.disk', 'r2');

        $this->assertSame('r2', $this->service->storageDisk());
    }

    public function test_storage_disk_defaults_to_local(): void
    {
        config()->set('export.module.storage.disk', null);

        $this->assertSame('local', $this->service->storageDisk());
    }

    public function test_storage_path_returns_configured_value_without_trailing_slash(): void
    {
        config()->set('export.module.storage.path', 'my/exports/');

        $this->assertSame('my/exports', $this->service->storagePath());
    }

    public function test_storage_path_defaults_to_exports(): void
    {
        config()->set('export.module.storage.path', null);

        $this->assertSame('exports', $this->service->storagePath());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeExport(array $overrides = []): ExportFile
    {
        return ExportFile::create(array_merge([
            'exportable_type' => 'user',
            'format'          => 'xlsx',
            'status'          => ExportStatus::Pending->value,
            'disk'            => 'local',
            'metadata'        => [],
        ], $overrides));
    }
}
