<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Export Namespace
    |--------------------------------------------------------------------------
    |
    | Namespace used to resolve export classes dynamically.
    | Example: page=user => App\Tools\Export\UserExport
    |
    */
    'namespace' => 'App\\Tools\\Export',

    /*
    |--------------------------------------------------------------------------
    | Translation Settings
    |--------------------------------------------------------------------------
    |
    | Static settings can be defined here.
    | Dynamic settings can be resolved using settings_resolver.
    |
    */
    'trans_file' => 'export',


    /*
    |--------------------------------------------------------------------------
    | PDF Settings
    |--------------------------------------------------------------------------
    |
    | Static settings can be defined here.
    | Dynamic settings can be resolved using settings_resolver.
    |
    */
    'pdf' => [
        'settings' => [
            // 'logo_url' => null,
            // 'company_name' => null,
        ],

        /*
        |--------------------------------------------------------------------------
        | Settings Resolver
        |--------------------------------------------------------------------------
        |
        | Supported:
        | - Closure
        | - Invokable class string
        | - [ClassName::class, 'method']
        |
        | The resolver must return array.
        |
        */
        'settings_resolver' => null,
    ],
];
