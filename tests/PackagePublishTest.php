<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Orchestra\Testbench\TestCase;

class PackagePublishTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    public function test_config_views_and_language_files_can_be_published(): void
    {
        @unlink(config_path('export.php'));
        @unlink(resource_path('views/vendor/export/pdf/export.blade.php'));
        @unlink(lang_path('vendor/export/en/pdf.php'));
        @unlink(database_path('migrations/create_export_files_table.php'));

        $this->artisan('vendor:publish', [
            '--tag' => 'export-builder-config',
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('vendor:publish', [
            '--tag' => 'export-builder-views',
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('vendor:publish', [
            '--tag' => 'export-builder-lang',
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('vendor:publish', [
            '--tag' => 'export-builder-migrations',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(config_path('export.php'));
        $this->assertFileExists(resource_path('views/vendor/export/pdf/export.blade.php'));
        $this->assertFileExists(lang_path('vendor/export/en/pdf.php'));
        $this->assertFileExists(database_path('migrations/create_export_files_table.php'));
    }
}
