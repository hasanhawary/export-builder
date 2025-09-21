<?php

namespace HasanHawary\ExportBuilder;

use Illuminate\Support\ServiceProvider;

class ExportBuilderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish the default export.php config
        $this->publishes([
            __DIR__ . '/../config/export.php' => config_path('export.php'),
        ], 'export-builder-config');
    }

    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__ . '/../config/export.php', 'export');
    }
}
