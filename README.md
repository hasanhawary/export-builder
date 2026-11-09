# Export Builder

[![Latest Stable Version](https://img.shields.io/packagist/v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![Total Downloads](https://img.shields.io/packagist/dm/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![PHP Version](https://img.shields.io/packagist/php-v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A modular Laravel package for building reusable, memory-safe Excel and PDF exports.  
Define one export class, get XLSX / XLS / CSV / PDF output, direct download or queued job — with relation support, advanced filters, permission gating, and full translation support.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Export Formats](#export-formats)
- [Column Types](#column-types)
- [Relation Exports](#relation-exports)
- [Custom Relations (Easy Mode)](#custom-relations-easy-mode)
- [Morph Relation Exports](#morph-relation-exports)
- [Advanced Filters](#advanced-filters)
- [Column Selection Filter](#column-selection-filter)
- [Languages](#languages)
- [PDF Output](#pdf-output)
- [Queued Exports](#queued-exports)
- [Routes](#routes)
- [Permissions](#permissions)
- [Translations](#translations)
- [The ExportFile model](#the-exportfile-model)
- [Overriding Controllers and Services](#overriding-controllers-and-services)
- [Performance](#performance)
- [Testing](#testing)

---

## Features

**Exports**
- **XLSX, XLS, CSV, PDF** — one export class drives every format
- **Memory-safe streaming** — `lazyById()` keyset pagination with a configurable chunk size; memory stays flat on large tables
- **Relations** — belongs-to / has-one, has-many (concat, list, count with alias), nested dot-notation, polymorphic, custom relations
- **Filters** — date range, advanced `whereIn` / `whereHas` / morph filters (unknown keys and hidden columns like `password` ignored), enum resolvers, runtime column selection
- **Automatic formatting** — text, int, float, money, date, datetime, bool, array, classPath, enum `resolve()`
- **One language per file** — `?lang=ar|en` (validated) or the app locale; Arabic files are fully Arabic and right-to-left (PDF and Excel), English files fully English; `php artisan export:translations` finds missing headings
- **Your brand on every PDF** — name (per language), logo, color and font from `.env` / config, or per tenant from a resolver; page numbers, repeated headers, automatic landscape

**Ready-made endpoints (optional module)**
- **Direct download** or **queued export** with an export history (list, show, download, delete)
- **History filters** — type, status, format, creator, date range, search, sorting, pagination
- **Ownership by default** — users only see, download and delete the exports they created
- **Trash** — delete keeps the file, restore brings it back, force delete removes record and file
- **Permissions** — `view-all` / `view-own` / `create` / `delete` abilities, per-page export abilities, custom resolver
- **permission-manager integration** — abilities are registered with `hasanhawary/permission-manager` automatically
- **Storage** — any Laravel disk (defaults to your app's default disk); every export gets its own file path
- **Safe by design** — host routes always win on conflict, only real export classes can be loaded from `page`, temp files are cleaned up, missing permissions answer `403` and missing files `404`

**Extensible**
- Override controllers, services and the permission resolver from config
- Publish config, PDF view, translations and migration
- `BaseExportContract` for custom export implementations

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1 – 8.5 |
| Laravel | 10, 11, 12, 13 |
| `maatwebsite/excel` | ^3.1 |
| `carlos-meneses/laravel-mpdf` | ^2.0 |

---

## Installation

### 1. Install

```bash
composer require hasanhawary/export-builder
```

The service provider is auto-discovered.

### 2. Publish the config

```bash
php artisan vendor:publish --tag=export-builder-config
```

### 3. Check the route middleware

The package endpoints require a logged-in user by default: `['api', 'auth:sanctum']` when Sanctum is installed, `['api', 'auth']` otherwise. Change it in `config/export.php` if your API uses another guard:

```php
'routes' => [
    'middleware' => ['api', 'auth:api'],
    // ...
],
```

### 4. Run the migration (queued exports and history)

```bash
php artisan migrate
```

The `export_files` migration is loaded from the package. Publish it only if you want to edit it: `php artisan vendor:publish --tag=export-builder-migrations`.

### 5. Choose where files are stored (optional)

Files go to your app's default disk (`FILESYSTEM_DISK`) under `exports/`. To use another disk or folder, set in `.env`:

```dotenv
EXPORT_DISK=s3
EXPORT_PATH=exports
```

### 6. Run a queue worker (queued exports only)

```bash
php artisan queue:work
```

To use a dedicated queue: `EXPORT_QUEUE=exports` (and `EXPORT_QUEUE_CONNECTION` for another connection). For big exports raise the limits: `EXPORT_QUEUE_TIMEOUT=900`, `EXPORT_QUEUE_TRIES=1`. A job that times out or whose worker dies marks its export `failed`, it never stays `processing`.

To empty the trash automatically, set `EXPORT_TRASH_DAYS=30` and make sure the Laravel scheduler runs (`php artisan schedule:run` every minute in cron). Trashed exports older than that are deleted with their files.

### 7. Create your first export class

See [Quick Start](#quick-start). Then call `GET api/export-direct?page=user`.

### 8. Permissions (optional)

Without permissions, anyone who passes the route middleware can export, and users only see their own exports. To control it per role:

**With `hasanhawary/permission-manager`:**

1. Give the abilities to your roles in `config/roles.php` (they are registered for you, nothing to add in `additional_operations`):

   ```php
   'roles' => [
       'employee' => ['permissions' => ['ExportFile' => ['create', 'view-own', 'delete', 'restore']]],
       'manager'  => ['permissions' => ['ExportFile' => ['create', 'view-all', 'delete', 'restore', 'force-delete']]],
   ],
   ```

2. For each page you export, add an `export` operation to its model and map it:

   ```php
   // app/Models/User.php
   public bool $inPermission = true;
   public array $specialOperations = ['export']; // → export-user
   ```

   ```php
   // config/export.php → module.permissions.pages
   'user' => ['export' => 'export-user', 'queue' => 'export-user'],
   ```

3. Create the permissions and turn the check on:

   ```bash
   php artisan permissions:reset --skip
   ```

   ```dotenv
   EXPORT_PERMISSIONS=true
   ```

**Without it:** create the same ability names with spatie (or any `can()` gate) and set `EXPORT_PERMISSIONS=true`. See [Permissions](#permissions).

### 9. Translations (optional)

The package's own texts work in Arabic and English without publishing anything. For your own columns, run `php artisan export:translations` to see which headings still need a translation (see [Languages](#languages)).


```bash
php artisan vendor:publish --tag=export-builder-lang
```

Publishes `lang/en/export.php` and `lang/ar/export.php`. To customise the PDF template: `php artisan vendor:publish --tag=export-builder-views`.

---

## Quick Start

Create an export class in the configured namespace (default: `App\Tools\Export`):

```php
namespace App\Tools\Export;

use App\Models\User;
use HasanHawary\ExportBuilder\BaseExport;

class UserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => User::class,
            'columns' => [
                'id'         => 'int',
                'name'       => 'text',
                'email'      => 'text',
                'is_active'  => 'bool',
                'created_at' => 'datetime',
            ],
        ], $filter);
    }
}
```

`page=user` resolves to `App\Tools\Export\UserExport`. Only classes that extend `BaseExport` (or implement `BaseExportContract`) can be loaded.

**Direct download in a controller:**

```php
use HasanHawary\ExportBuilder\ExportBuilder;

public function export(Request $request)
{
    return (new ExportBuilder($request->validated()))->response();
}
```

**Or through the facade:**

```php
use HasanHawary\ExportBuilder\Facades\Export;

return Export::make(['page' => 'user', 'format' => 'xlsx'])->response();
```

**Via package routes:**

```
GET  api/export-direct?page=user&format=xlsx
GET  api/export-direct?page=user&format=pdf&start=2026-01-01&end=2026-06-30
POST api/export          { "page": "user", "format": "xlsx" }   ← queued
GET  api/export-log                                              ← history
```

---

## Configuration

`config/export.php` (values shown are the defaults):

```php
return [
    // Namespace where export classes live
    'namespace'  => 'App\\Tools\\Export',

    // Rows per chunk for lazyById() streaming          (.env: EXPORT_CHUNK_SIZE)
    'chunk_size' => 500,

    // Translations: 'export' → package files, 'api' → your lang/{locale}/api.php
    'trans_file' => 'export',

    // Languages a request may ask for with ?lang= (else 422), and right-to-left ones
    'locales'     => ['ar', 'en'],
    'rtl_locales' => ['ar', 'fa', 'he', 'ur'],

    'pdf' => [
        'brand' => [
            'name'          => null,        // string or ['ar' => ..., 'en' => ...]   (.env: EXPORT_BRAND_NAME)
            'logo'          => null,        // URL, absolute path or public/ path       (.env: EXPORT_BRAND_LOGO)
            'primary_color' => '#1e3a5f',   //                                          (.env: EXPORT_BRAND_COLOR)
            'font'          => 'dejavusanscondensed', //                                (.env: EXPORT_PDF_FONT)
        ],
        'orientation'            => 'auto', // auto | portrait | landscape            (.env: EXPORT_PDF_ORIENTATION)
        'landscape_from_columns' => 8,
        'mpdf'                   => [],     // extra mPDF options
        'settings'          => [],      // legacy: company_name, logo_url, primary_color (override 'brand')
        'settings_resolver' => null,    // per tenant: Closure / invokable class / [Class, 'method'] returning settings
    ],

    'module' => [
        'enabled' => true,

        'routes' => [
            'enabled'     => true,
            'middleware'  => ['api', 'auth:sanctum'],   // 'auth' when Sanctum is not installed
            'prefix'      => 'api',
            'export_path' => 'export',
            'direct_path' => 'export-direct',
            'log_path'    => 'export-log',
            'name_prefix' => 'export-builder.export.',
        ],

        'controllers' => [ /* override the package controllers */ ],
        'services'    => [ /* override export / export_file / permissions services */ ],

        'storage' => [
            'disk' => null,             // null → config('filesystems.default')  (.env: EXPORT_DISK)
            'path' => 'exports',        //                                        (.env: EXPORT_PATH)
        ],

        'queue' => [
            'connection' => null,       // null → default connection   (.env: EXPORT_QUEUE_CONNECTION)
            'name'       => null,       // null → default queue        (.env: EXPORT_QUEUE)
            'timeout'    => null,       // seconds                     (.env: EXPORT_QUEUE_TIMEOUT)
            'tries'      => null,       //                             (.env: EXPORT_QUEUE_TRIES)
            'run_as_requester' => true, // the job runs logged in as the user who asked for the export
        ],

        'trash' => [
            'prune_after_days' => null, // delete trashed exports + files after N days (.env: EXPORT_TRASH_DAYS)
        ],

        'max_per_page'  => 500,         // larger per_page values are lowered (-1 still returns everything)
        'max_bulk_ids'  => 500,         // ids per delete / restore / force-delete request
        'expose_errors' => null,        // raw job errors in the API: null → only when app.debug is on

        'permissions' => [
            'enabled'   => false,       // (.env: EXPORT_PERMISSIONS)
            'register'  => true,        // register abilities with hasanhawary/permission-manager
            'abilities' => [
                'export'   => 'export',
                'queue'    => 'create-export-file',
                'view_all' => 'view-all-export-file',
                'view_own' => 'view-own-export-file',
                'delete'   => 'delete-export-file',
                'restore'      => 'restore-export-file',
                'force_delete' => 'force-delete-export-file',
            ],
            'pages' => [
                // 'user' => ['export' => 'export-user', 'queue' => 'export-user'],
            ],
        ],
    ],
];
```

**Upgrading:** a config published by an older version keeps working. Keys it does not have (`queue`, `permissions.register`) fall back to the defaults above, and an explicit `storage.disk` (e.g. `'local'`) is still used as-is.

---

## Export Formats

| Format | Parameter | Notes |
|---|---|---|
| XLSX | `format=xlsx` | Default |
| XLS | `format=xls` | |
| CSV | `format=csv` | |
| PDF | `format=pdf` | Uses Blade view |

---

## Column Types

| Type | Output |
|---|---|
| `text` | Raw string value |
| `int` | Cast to integer |
| `float` | Cast to float (non-numeric returned as-is) |
| `money` | `number_format($v, 2, '.', '')` |
| `date` | `YYYY-MM-DD` |
| `datetime` | `YYYY-MM-DD HH:MM:SS` |
| `bool` / `boolean` | Translated Yes / No |
| `array` | `implode(' , ', array_filter($v))` |
| `classPath` | Class basename, translated if key exists |
| `MyEnum::class` | Calls `MyEnum::resolve($value)` |

---

## Relation Exports

```php
parent::__construct([
    'model'   => User::class,
    'columns' => ['id' => 'int', 'name' => 'text'],

    'relations' => [
        // One-to-one / BelongsTo
        'one' => [
            'role_id' => ['role' => ['name' => 'text']],
        ],

        'many' => [
            // Concat all related values into one cell
            'concat' => [
                'tags' => ['label' => 'text'],
            ],

            // Multi-line block per related item
            'list' => [
                'addresses' => ['city' => 'text', 'country' => 'text'],
            ],

            // Count with optional alias
            'count' => [
                'orders as orders_total',
            ],
        ],

        // Nested — automatically resolved as dot-notation with()
        // e.g. 'department_id' => ['department' => ['company' => ['name' => 'text']]]
    ],
], $filter);
```

**Custom eager loads and selects:**

```php
'customWith'   => ['settings', 'profile'],       // extra with() paths
'customSelect' => ['id', 'name', 'email'],        // restrict SELECT columns
'additionalQuery' => [
    'posts_count' => fn ($q) => $q->withCount('posts'),
],
```

---

## Custom Relations (Easy Mode)

`customRelations()` is the simplest way to export related data without touching the `relations` config array. Override it in your export class and define columns with closures or attribute names — no schema-level config needed.

```php
class UserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => User::class,
            'columns' => ['id' => 'int', 'name' => 'text', 'email' => 'text'],
            // No 'relations' key needed — customRelations() handles it below
        ], $filter);
    }

    public function customRelations(): array
    {
        return [
            // Simple attribute access
            'role' => ['name'],

            // Callable — full control over the value
            'profile' => [
                'full_address' => fn ($profile) => "{$profile->city}, {$profile->country}",
                'avatar_url'   => fn ($profile) => $profile->avatar ?? 'N/A',
            ],

            // Collection relation — one column per item with an index prefix
            'permissions' => ['name'],
        ];
    }
}
```

**How it works:**

| Config key | Value type | Output column name |
|---|---|---|
| `'role' => ['name']` | Attribute string | `role_name` |
| `'profile' => ['full_address' => fn]` | Callable | `profile_full_address` |
| Collection relation | Any attribute | `permissions_0_name`, `permissions_1_name`, … |

**Rules:**
- The array key is the Eloquent relation name (e.g. `role` → `$model->role`)
- When the relation is a `Collection`, each item gets an index prefix (`relation_0_key`, `relation_1_key`)
- When the relation is a single model, the key is `relation_column`
- `strip_tags()` is applied to all values automatically
- Columns ending in `_id` are automatically removed from the heading row
- Works with the `related` column filter — clients can request `related[]=role_name`

**Compared to `relations` config:**

| | `relations` config | `customRelations()` |
|---|---|---|
| Setup | Declare in constructor array | Override one method |
| Nested relations | Yes (dot-notation) | No |
| Callables | No | Yes |
| Collection indexing | No | Yes |
| Column filter support | Yes | Yes |
| Best for | Standard BelongsTo / HasMany | Custom display logic, computed values |

---

## Morph Relation Exports

```php
'relations' => [
    'morph' => [
        'sourceable_id' => [
            'relation' => 'sourceable',   // Eloquent morphTo method
            'column'   => 'name',         // Column to display
            'type'     => 'text',         // convertValue type (default: text)
            'fallback' => null,           // Value when relation is null
        ],
    ],
],
```

---

## Advanced Filters

Request body:

```json
{
  "page": "user",
  "format": "xlsx",
  "advanced": [
    { "key": "status",  "value": ["active", "pending"] },
    { "key": "role_id", "value": 3 }
  ]
}
```

Only keys matching actual table columns or configured relation keys are accepted — all others are silently ignored to prevent SQL injection.

**Relation filter config:**

```php
'filterRelations' => [
    'many' => [
        'role_id' => ['relation' => 'role', 'column' => 'id'],

        // With morph constraint:
        'source_id' => [
            'relation'    => 'sourceable',
            'morph'       => 'sourceable',
            'morph_types' => [Campaign::class, Sponsor::class],
            'column'      => 'id',
        ],
    ],
],
```

**Enum resolver:**

```php
protected array $resolvers = [
    'status' => ['enum' => StatusEnum::class, 'method' => 'fromLabel'],
];
```

---

## Column Selection Filter

Clients can request a subset of columns at runtime:

```
GET api/export-direct?page=user&format=xlsx&columns[]=id&columns[]=name&related[]=roles
```

Or via JSON:

```json
{
  "page": "user",
  "format": "xlsx",
  "columns": ["id", "name", "email"],
  "related": ["roles", "orders_total"]
}
```

`columns` filters base columns. `related` filters relation columns (concat, list, count).

---

## Languages

Every file is built in one language: headings, yes/no values, dates, the PDF and the Excel sheet direction.

- Send `lang` with any export request: `GET api/export-direct?page=user&lang=ar`, `POST api/export { "page": "user", "lang": "en" }`.
- Allowed values come from `export.locales` (default `['ar', 'en']`). Case and region are ignored: `AR`, `ar_EG`, `ar-eg` → `ar`. Anything else answers `422`.
- Without `lang`, the current app locale is used (whatever your middleware set, e.g. from `Accept-Language`).
- Queued exports remember the requester's language and are built in it by the worker.
- `export.rtl_locales` (default `ar, fa, he, ur`) get a right-to-left PDF and a right-to-left Excel sheet.
- The app locale is restored after the export, even when it fails.

**Keep each file in one language.** The package translates its own texts and common headings (`id`, `name`, `email`, `status`, ...). Your own columns need a translation in each language, otherwise the column key is shown. Check every export at once:

```bash
php artisan export:translations              # all pages, all locales
php artisan export:translations user --locale=ar
```

It lists every heading and title missing a translation and exits with `1` when something is missing (use it in CI). Add the keys to your translation file (`export.trans_file`, e.g. `lang/ar/export.php`):

```php
return [
    'salary'      => 'الراتب',
    'users_title' => 'تقرير المستخدمين',   // PDF title for UserExport
];
```

---

## PDF Output

The default template is `export::pdf.export`: your brand, the title, a summary (record count, date range, generated at / by), the table with a repeated header row on every page, and a footer with "Page X of Y".

- The title is the `filename` the user gave, else the `{page}_title` / `{page}` translation, else (English only) the class name, else the translated "Export Report". An Arabic file never gets an English title.
- Dates and numbers are kept left-to-right inside Arabic text, so `2026-11-09` never shows as `09-11-2026`.
- Landscape is used automatically from 8 columns (`export.pdf.orientation`: `auto` | `portrait` | `landscape`, `landscape_from_columns`).
- Rows are loaded in chunks and only their values are kept, but mPDF builds the whole document in memory. For very large datasets prefer `xlsx` / `csv`, which stream.

### Your project's identity

Set once per project in `.env`, no view to publish:

```dotenv
EXPORT_BRAND_NAME="National Water Company"
EXPORT_BRAND_LOGO=images/logo.png        # URL, absolute path, or a path inside public/
EXPORT_BRAND_COLOR=#0f5c8c
EXPORT_PDF_FONT=dejavusanscondensed      # any mPDF font; covers Arabic and Latin
```

For a name per language, set it in `config/export.php`. The name is only shown when it exists for the file's language:

```php
'pdf' => [
    'brand' => [
        'name' => ['ar' => 'شركة المياه الوطنية', 'en' => 'National Water Company'],
    ],
],
```

**Per tenant** — return the brand from a resolver (Closure, invokable class or `[Class, 'method']`); it overrides the config:

```php
'pdf' => [
    'settings_resolver' => [App\Services\TenantBrand::class, 'forExport'],
],

// return ['company_name' => ['ar' => '...', 'en' => '...'], 'logo_url' => '...', 'primary_color' => '#...', 'font' => '...'];
```

The older `settings` keys (`company_name`, `logo_url`) still work. Unsafe values (scripts, `javascript:` links, CSS in the color or font) are ignored.

### Custom template

```bash
php artisan vendor:publish --tag=export-builder-views
```

Or per export class:

```php
public function pdfView(): string
{
    return 'exports.my-custom-template';
}
```

The view receives `$title`, `$columns`, `$rows`, `$brand`, `$locale`, `$is_rtl`, `$start`, `$end`, `$generated_at`, `$generated_by`, `$settings` and `$trans_prefix`.

---

## Queued Exports

```
POST api/export
{ "page": "user", "format": "xlsx" }
```

Response (202 Accepted):

```json
{
  "data": {
    "id": 1,
    "exportable_type": "user",
    "format": "xlsx",
    "status": "pending",
    "file_url": null
  },
  "message": "Export started successfully."
}
```

Poll for completion:

```
GET api/export-log           ← list all (paginated, ?per_page=15&status=completed)
GET api/export-log/{id}      ← show one
GET api/export-log/{id}/download  ← stream the file
DELETE api/export-log/{id}          ← move one export to the trash (file kept)
DELETE api/export-log/delete        ← move several to the trash: { "ids": [1, 2] }
POST   api/export-log/restore       ← bring back from the trash, with its file
DELETE api/export-log/force-delete  ← delete permanently: record and file
```

The trash is listed with `GET api/export-log?trashed=only`. Trashed exports cannot be shown or downloaded until they are restored. Force delete works on trashed and active exports.

For `delete`, `restore` and `force-delete`, send the ids in the request body. Each record is checked against the user's permissions; if any one is not allowed, nothing is changed and the response is `403`.

```json
{ "ids": [1, 2, 3] }
```

### Filtering the export history

`GET api/export-log` accepts these query parameters:

| Parameter | Example | Description |
|---|---|---|
| `exportable_type` / `type` | `type=user` | Export page (the `page` value sent to `POST api/export`). `type` is a short alias. |
| `status` | `status=completed` | `pending`, `processing`, `completed`, `failed` |
| `format` | `format=pdf` | `xlsx`, `xls`, `csv`, `pdf` |
| `created_by` | `created_by=10` | ID of the user who started the export |
| `start` | `start=2026-01-01` | Created on or after this date |
| `end` | `end=2026-01-31` | Created on or before this date (whole day included) |
| `search` | `search=invoice` | Matches id, file name, type, format or status |
| `sort_column` | `sort_column=created_at` | Any `export_files` column (default `id`) |
| `sort_direction` | `sort_direction=asc` | `asc` or `desc` (default `desc`) |
| `trashed` | `trashed=only` | `only` = the trash, `with` = active and trashed (default: active only) |
| `per_page` | `per_page=15` | Page size; `-1` returns everything |
| `page` | `page=2` | Page number |

`exportable_type`, `status`, `format` and `created_by` accept several values as a comma-separated list or an array:

```
GET api/export-log?format=pdf,xlsx&status=completed
GET api/export-log?status[]=pending&status[]=failed
GET api/export-log?type=user&start=2026-01-01&end=2026-01-31&page=2
```

Empty values and invalid dates are ignored. The list is always limited to the exports the current user may see, and filters only narrow that further (see [Permissions](#permissions)).

Files are stored on `storage.disk` (your app's default disk when not set) under `storage.path`, as `exports/{id}_{file-name}`, so two exports with the same name never overwrite each other. The download keeps the readable `file_name`.

If a stored file was removed from the disk, `GET api/export-log/{id}/download` answers `404`.

---

## Routes

All package routes default to the `api` prefix. They never claim a URI already owned by the host app.

| Method | URI | Route name | Description |
|---|---|---|---|
| `GET` | `api/export-direct` | `export-builder.export.direct` | Direct file download |
| `GET` | `api/export` | `export-builder.export.download` | Direct file download (alias) |
| `POST` | `api/export` | `export-builder.export.store` | Create queued export |
| `GET` | `api/export-log` | `export-builder.export.logs.index` | List export history |
| `GET` | `api/export-log/{id}` | `export-builder.export.logs.show` | Show one export record |
| `GET` | `api/export-log/{id}/download` | `export-builder.export.logs.download` | Download exported file |
| `DELETE` | `api/export-log/{id}` | `export-builder.export.logs.destroy` | Move one export to the trash, file kept |
| `DELETE` | `api/export-log/delete` | `export-builder.export.logs.destroy-many` | Move several to the trash (`ids` in the body) |
| `POST` | `api/export-log/restore` | `export-builder.export.logs.restore` | Restore from the trash (`ids` in the body) |
| `DELETE` | `api/export-log/force-delete` | `export-builder.export.logs.force-delete` | Delete permanently, record and file (`ids` in the body) |

`{id}` only matches numbers.

**Disable all package routes:**

```php
'module' => ['enabled' => false],
```

**Move to a different prefix:**

```php
'routes' => [
    'prefix'      => 'internal/reports',
    'name_prefix' => 'reports.',
],
```

---

## Permissions

Disabled by default. While disabled, users can still only list, show, download and delete their own exports (`created_by` = current user); guests can only reach exports with no owner.

Enable and configure per-page abilities:

```php
'permissions' => [
    'enabled' => true,
    'abilities' => [
        'export'   => 'export',
        'queue'    => 'create-export-file',
        'view_all' => 'view-all-export-file',
        'view_own' => 'view-own-export-file',
        'delete'   => 'delete-export-file',
        'restore'      => 'restore-export-file',
        'force_delete' => 'force-delete-export-file',
    ],
    'pages' => [
        'user' => [
            'export' => 'export-user',
            'queue'  => 'export-user',
        ],
    ],
],
```

### Registering the permissions in your project

The abilities above must exist in your project's permission table, otherwise nobody can be given them. Permissions that are not created yet are treated as "not allowed" (`403`), never as a server error.

**With [hasanhawary/permission-manager](https://packagist.org/packages/hasanhawary/permission-manager)**

Nothing to add: when permission-manager is installed, this package registers an `ExportFile` entry in `roles.additional_operations` automatically. `php artisan permissions:reset` then creates exactly the default abilities:

```
create-export-file
view-all-export-file
view-own-export-file
delete-export-file
restore-export-file
force-delete-export-file
```

The operations follow `permissions.abilities`: every ability ending in `-export-file` is registered (rename `view_all` to `read-export-file` and you get `read-export-file`). Abilities without that suffix, like the default `export`, are skipped.

To control it yourself, either define your own `ExportFile` entry in `config/roles.php` (yours always wins, the package will not add a second one), or turn the automatic registration off:

```php
// config/export.php
'permissions' => ['register' => false, /* ... */],
```

Optional: name the group in your role translations (`lang/{locale}/roles.php`), e.g. `'export_file' => 'Export Files'`.

Give them to your roles in `config/roles.php`:

```php
'roles' => [
    'employee' => [
        'permissions' => [
            'ExportFile' => ['create', 'view-own', 'delete', 'restore'],
        ],
    ],
    'manager' => [
        'permissions' => [
            'ExportFile' => ['create', 'view-all', 'delete', 'restore', 'force-delete'],
        ],
    ],
],
```

For exporting a page, add an `export` operation to that page's model (for example `App\Models\User`) and map it per page:

```php
// app/Models/User.php
public bool $inPermission = true;
public array $specialOperations = ['export']; // → export-user
```

```php
// config/export.php
'pages' => [
    'user' => [
        'export' => 'export-user',
        'queue'  => 'export-user',
    ],
],
```

Then create the permissions and turn the check on:

```bash
php artisan permissions:reset --skip
```

```php
// config/export.php
'permissions' => ['enabled' => true, /* ... */],
```

**Without permission-manager** — create the same names with spatie directly, e.g. `Permission::create(['name' => 'view-own-export-file'])`, or use any ability your `User::can()` understands.

**Custom resolver** — replace the entire permission logic:

```php
'services' => [
    'permissions' => App\Services\MyExportPermissionResolver::class,
],
```

Your class must extend `ExportPermissionResolver` or implement the same public API (`canExport`, `canCreateQueued`, `canList`, `canView`, `canDelete`, `scopeForUser`).

---

## Translations

The package ships with English and Arabic translations for column headings and boolean values.

**Published to:** `lang/{en,ar}/export.php`

| Key | English | Arabic |
|---|---|---|
| `id` | ID | المعرف |
| `name` | Name | الاسم |
| `email` | Email | البريد الإلكتروني |
| `is_active` | Active | نشط |
| `created_at` | Created At | تاريخ الإنشاء |
| `yes` | Yes | نعم |
| `no` | No | لا |

**Use your own translation file** — point to any file in your project's `lang/` directory:

```php
// config/export.php
'trans_file' => 'my_exports',  // looks up lang/en/my_exports.php keys
```

**Add custom column translations** — add keys to your published `lang/en/export.php` (and `lang/ar/export.php`):

```php
return [
    // ...existing keys...
    'order_number' => 'Order #',
    'total_amount' => 'Total',
];
```

---

## The ExportFile model

`HasanHawary\ExportBuilder\Models\ExportFile` is a normal Eloquent model (soft deletes) you can query in your app.

`exportable()` returns the exported record when `exportable_id` is set, and `null` otherwise. `exportable_type` can be a page slug (`user` → the model of `UserExport`), a morph-map alias or a model class; a type that cannot be resolved gives `null`, never a "class not found" error. It works with eager loading:

```php
$export->exportable()->associate($invoice)->save();

ExportFile::with('exportable')->get();
```

---

## Overriding Controllers and Services

Every package class can be replaced from config:

```php
'module' => [
    'controllers' => [
        'direct' => App\Http\Controllers\CustomExportController::class,
        'jobs'   => App\Http\Controllers\CustomExportJobController::class,
    ],
    'services' => [
        'export'      => App\Services\CustomExportService::class,
        'export_file' => App\Services\CustomExportFileService::class,
        'permissions' => App\Services\CustomExportPermissionResolver::class,
    ],
],
```

---

## Performance

| Feature | Detail |
|---|---|
| **Lazy streaming** | `lazyById()` cursor — peak memory stays flat at any dataset size |
| **Configurable chunk size** | `export.chunk_size` (default 500) |
| **FK detection cache** | Computed once per export instance |
| **Schema column cache** | Static cache per table, one `SHOW COLUMNS` per request |
| **Query cache** | `buildQuery()` result cached per instance |
| **PDF settings cache** | Resolver called once per `ExportBuilder` instance |

---

## Testing

```bash
composer test
```

The test suite covers:

- Excel generation with spreadsheet readback (all column types)
- PDF generation with Blade view
- Relation exports: belongs-to, has-many concat, count alias, nested, morph
- Column filter — heading/map consistency, PDF filter safety
- Route registration, host conflict protection, disable/enable
- Permission deny/allow for direct, queued, list, download, delete
- Custom controller and service overrides
- `ExportFileService` full lifecycle and delete edge cases
- `AdvancedFilter` security allowlist, relation filters, enum resolver, error recovery
- `HelperTrait::convertValue` — all 10+ type branches and edge cases
- `ExportBuilder::buildFileName` — naming consistency between direct and queued paths
- Storage config SSOT via `storageDisk()` / `storagePath()`, default-disk fallback
- Queued job: unique file path per export, temp-file cleanup, failure status, queue config
- Safe download (`404` for missing files), `file_url` on disks without URL support
- Export history filters, ownership scoping, `view-all` / `view-own` rules, unseeded permissions
- permission-manager auto-registration
- `wrapPaginate()` page sizes and metadata
- Architecture regression guards (SSOT fixes)
- Edge cases: morph, nested relations, `customWith`, `customSelect`, PDF resolver variants

---

## License

MIT © [Hasan Hawary](https://github.com/hasanhawary)
