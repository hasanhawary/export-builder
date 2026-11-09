<?php

namespace HasanHawary\ExportBuilder;

use HasanHawary\ExportBuilder\Services\ExportService;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use HasanHawary\ExportBuilder\Support\PermissionManagerOperations;
use HasanHawary\ExportBuilder\Listeners\MarkExportAsFailed;
use HasanHawary\ExportBuilder\Models\ExportFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
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

        // Publish language files directly into the project's lang directory.
        // Publishes to: lang/en/export.php, lang/ar/export.php (not under vendor/).
        $this->publishes([
            __DIR__ . '/../resources/lang/en' => lang_path('en'),
            __DIR__ . '/../resources/lang/ar' => lang_path('ar'),
        ], 'export-builder-lang');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Make the export-file permissions known to hasanhawary/permission-manager (if installed).
        $this->app->make(PermissionManagerOperations::class)->register();

        // Exports whose job was killed (timeout, crash) are marked failed instead of staying "processing".
        Event::listen(JobFailed::class, MarkExportAsFailed::class);

        // Empty the trash daily when export.module.trash.prune_after_days is set (needs the scheduler running).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((int) config('export.module.trash.prune_after_days') > 0) {
                $schedule->command('model:prune', ['--model' => [ExportFile::class]])->daily();
            }
        });

        $this->app->booted(function (): void {
            $this->app->make(ExportRoutes::class)->register();
        });
    }

    public function register(): void
    {
        // Generic helpers, only for apps that do not have their own. Loaded here (after Composer
        // included the app's helper files) so the app's versions are never replaced.
        require_once __DIR__ . '/fallback-helpers.php';

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
