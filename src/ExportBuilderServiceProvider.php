<?php

namespace HasanHawary\ExportBuilder;

use HasanHawary\ExportBuilder\Services\ExportService;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use Illuminate\Support\ServiceProvider;

class ExportBuilderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish the default export.php config
        $this->publishes([
            __DIR__ . '/../config/export.php' => config_path('export.php'),
        ], 'export-builder-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_export_files_table.php' => database_path('migrations/create_export_files_table.php'),
        ], 'export-builder-migrations');

        // Load views from the package
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'export');

        // Load translation files from the package
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'export');

        // Publish views for customization
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/export'),
        ], 'export-builder-views');

        // Publish language files to lang/vendor/export/ (project root lang directory)
        $this->publishes([
            __DIR__ . '/../resources/lang' => lang_path('vendor/export'),
        ], 'export-builder-lang');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->booted(function (): void {
            $this->app->make(ExportRoutes::class)->register();
        });
    }

    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(__DIR__ . '/../config/export.php', 'export');

        // Bind ExportBuilder to the container for Facade support.
        // The facade calls ExportBuilder::response() but filters must be provided
        // at call time via app()->makeWith(ExportBuilder::class, ['filter' => $filters])
        // or directly: new ExportBuilder($filters)->response()
        $this->app->bind(ExportBuilder::class, function ($app, array $params = []) {
            return new ExportBuilder($params['filter'] ?? []);
        });

        $this->app->singleton(ExportRoutes::class, function ($app) {
            return new ExportRoutes;
        });

        $this->app->bind(ExportService::class, function ($app) {
            $service = config('export.module.services.export', ExportService::class);

            if ($service === ExportService::class) {
                return new ExportService;
            }

            return $app->make($service);
        });

        $this->app->bind(ExportFileService::class, function ($app) {
            $service = config('export.module.services.export_file', ExportFileService::class);

            if ($service === ExportFileService::class) {
                return new ExportFileService;
            }

            return $app->make($service);
        });

        $this->app->bind(ExportPermissionResolver::class, function ($app) {
            $service = config('export.module.services.permissions', ExportPermissionResolver::class);

            if ($service === ExportPermissionResolver::class) {
                return new ExportPermissionResolver;
            }

            return $app->make($service);
        });
    }
}
