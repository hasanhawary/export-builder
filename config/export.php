<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export Namespace
    |--------------------------------------------------------------------------
    |
    | Namespace used to resolve export classes from the "page" value.
    | Example: page=user => App\Tools\Export\UserExport
    |
    */
    'namespace' => 'App\\Tools\\Export',

    /*
    |--------------------------------------------------------------------------
    | Streaming Chunk Size
    |--------------------------------------------------------------------------
    |
    | Rows fetched per chunk when streaming large exports via lazyById().
    | Higher is faster, lower uses less memory per chunk.
    |
    */
    'chunk_size' => (int) env('EXPORT_CHUNK_SIZE', 500),

    /*
    |--------------------------------------------------------------------------
    | Translation File
    |--------------------------------------------------------------------------
    |
    | Where column headings, yes/no values and statuses are translated from.
    | - 'export' → the package's own file (publish it to customise)
    | - 'api'    → your app's lang/{locale}/api.php
    |
    */
    'trans_file' => 'export',

    /*
    |--------------------------------------------------------------------------
    | PDF Settings
    |--------------------------------------------------------------------------
    |
    | 'settings' are passed to the PDF view as $settings.
    | 'settings_resolver' can return them dynamically (merged over 'settings'):
    | a Closure, an invokable class string, or [ClassName::class, 'method'].
    | It must return an array.
    |
    */
    'pdf' => [
        'settings' => [
            // 'logo_url' => null,
            // 'company_name' => null,
        ],

        'settings_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Export Module
    |--------------------------------------------------------------------------
    |
    | Ready-made endpoints: direct download, queued exports and export history.
    | Routes never replace a route your app already defines (same name or URI).
    |
    */
    'module' => [
        'enabled' => true,

        'routes' => [
            'enabled' => true,

            // Logged-in users only: 'auth:sanctum' when Sanctum is installed, otherwise your default 'auth' guard.
            'middleware' => ['api', class_exists(\Laravel\Sanctum\Sanctum::class) ? 'auth:sanctum' : 'auth'],

            'prefix' => 'api',
            'export_path' => 'export',
            'direct_path' => 'export-direct',
            'log_path' => 'export-log',
            'name_prefix' => 'export-builder.export.',
        ],

        'controllers' => [
            'direct' => HasanHawary\ExportBuilder\Http\Controllers\ExportController::class,
            'jobs' => HasanHawary\ExportBuilder\Http\Controllers\ExportJobController::class,
        ],

        'services' => [
            'export' => HasanHawary\ExportBuilder\Services\ExportService::class,
            'export_file' => HasanHawary\ExportBuilder\Services\ExportFileService::class,
            'permissions' => HasanHawary\ExportBuilder\Services\ExportPermissionResolver::class,
        ],

        'storage' => [
            // null → your app's default disk (config('filesystems.default')).
            'disk' => env('EXPORT_DISK'),
            'path' => env('EXPORT_PATH', 'exports'),
        ],

        // Queue for the queued-export job. null → your app's default connection / queue / worker limits.
        // A killed or timed-out job marks its export "failed" (it never stays "processing").
        'queue' => [
            'connection' => env('EXPORT_QUEUE_CONNECTION'),
            'name' => env('EXPORT_QUEUE'),
            'timeout' => env('EXPORT_QUEUE_TIMEOUT'),   // seconds
            'tries' => env('EXPORT_QUEUE_TRIES'),
        ],

        // Trash: deleted exports keep their file until restored or force-deleted.
        // Set a number of days to delete them permanently (record + file) automatically.
        // Runs daily via `model:prune` (needs `php artisan schedule:run` in cron). null → never.
        'trash' => [
            'prune_after_days' => env('EXPORT_TRASH_DAYS'),
        ],

        // Request limits for the history endpoints.
        'max_per_page' => 100,   // per_page above this is lowered, as in 2.4.3 (per_page=-1 still returns everything)
        'max_bulk_ids' => 500,   // ids per delete / restore / force-delete request

        // Show the raw job error (SQL, paths) in the API. null → only when app.debug is on;
        // otherwise users get a translated "could not be created" message. Always logged.
        'expose_errors' => null,

        'permissions' => [
            // Off: no ability checks (anyone who passes the route middleware can export),
            // and users only see / download / delete the files they created.
            'enabled' => (bool) env('EXPORT_PERMISSIONS', false),

            // Register the "*-export-file" abilities with hasanhawary/permission-manager automatically
            // (ExportFile entry in roles.additional_operations). Ignored if that package is not installed.
            'register' => true,

            'abilities' => [
                'export' => 'export',               // direct download, for pages not listed in 'pages'
                'queue' => 'create-export-file',    // queued export, for pages not listed in 'pages'
                'view_all' => 'view-all-export-file',
                'view_own' => 'view-own-export-file',
                'delete' => 'delete-export-file',
            ],

            // Per-page abilities, e.g. page=user:
            'pages' => [
                // 'user' => [
                //     'export' => 'export-user',
                //     'queue' => 'export-user',
                // ],
            ],
        ],
    ],
];
