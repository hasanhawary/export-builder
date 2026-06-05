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
- [PDF Output](#pdf-output)
- [Queued Exports](#queued-exports)
- [Routes](#routes)
- [Permissions](#permissions)
- [Translations](#translations)
- [Overriding Controllers and Services](#overriding-controllers-and-services)
- [Performance](#performance)
- [Testing](#testing)

---

## Features

- **XLSX, XLS, CSV, PDF** — one export class drives all formats
- **Memory-safe streaming** — `lazyById()` cursor, configurable chunk size, peak memory stays flat regardless of dataset size
- **Direct download or queued job** — same export class, two delivery modes
- **Relation support** — belongs-to/has-one, has-many concat, has-many list, count with alias, nested dot-notation, polymorphic
- **Advanced filters** — `whereIn`, `whereHas`, morph constraints, enum resolvers
- **Column selection filter** — client can request a subset of columns at runtime
- **Automatic type formatting** — text, int, float, money, date, datetime, bool, array, classPath, Enum
- **Full translation support** — English and Arabic built-in; headings and bool values auto-translated
- **Permission gating** — per-page permission config, custom resolver override, scoped list visibility
- **Safe package routes** — never claims `/export`; host routes always win on conflict
- **Publishable** — config, views, migrations, and lang files are all publishable
- **Contracts / interfaces** — `BaseExportContract` for type-hinting custom implementations
- **121 tests, 257 assertions**

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.1 – 8.5 |
| Laravel | 10, 11, 12, 13 |
| `maatwebsite/excel` | ^3.1 or ^4.0 |
| `carlos-meneses/laravel-mpdf` | ^2.0 |

---

## Installation

```bash
composer require hasanhawary/export-builder
```

Laravel auto-discovers the service provider. No manual registration needed.

**Optional publishes:**

```bash
# Config
php artisan vendor:publish --tag=export-builder-config

# PDF Blade view (customise the template)
php artisan vendor:publish --tag=export-builder-views

# Language files (en + ar)
php artisan vendor:publish --tag=export-builder-lang

# Migration for queued export history
php artisan vendor:publish --tag=export-builder-migrations
php artisan migrate
```

Published lang files land in `lang/vendor/export/{en,ar}/export.php`.

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

`page=user` resolves to `App\Tools\Export\UserExport`.

**Direct download in a controller:**

```php
use HasanHawary\ExportBuilder\ExportBuilder;

public function export(Request $request)
{
    return (new ExportBuilder($request->validated()))->response();
}
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

Publish and edit `config/export.php`:

```php
return [
    // Namespace where export classes live
    'namespace'  => 'App\\Tools\\Export',

    // Translation file for column headings and bool values
    // 'export' → package built-in (en + ar)
    // 'api'    → use your own lang/en/api.php
    'trans_file' => 'export',

    // Rows per chunk for lazyById() streaming (memory-safe)
    'chunk_size' => 500,

    'pdf' => [
        'settings'          => [],      // static: logo_url, company_name, etc.
        'settings_resolver' => null,    // callable / invokable / [Class, 'method']
    ],

    'module' => [
        'enabled' => true,

        'routes' => [
            'enabled'      => true,
            'middleware'   => ['api'],
            'prefix'       => 'api',
            'export_path'  => 'export',
            'direct_path'  => 'export-direct',
            'log_path'     => 'export-log',
            'name_prefix'  => 'export-builder.export.',
        ],

        'controllers' => [
            'direct' => \HasanHawary\ExportBuilder\Http\Controllers\ExportController::class,
            'jobs'   => \HasanHawary\ExportBuilder\Http\Controllers\ExportJobController::class,
        ],

        'services' => [
            'export'      => \HasanHawary\ExportBuilder\Services\ExportService::class,
            'export_file' => \HasanHawary\ExportBuilder\Services\ExportFileService::class,
            'permissions' => \HasanHawary\ExportBuilder\Services\ExportPermissionResolver::class,
        ],

        'storage' => [
            'disk' => 'local',
            'path' => 'exports',
        ],

        'permissions' => [
            'enabled'   => false,
            'abilities' => [
                'export'   => 'export',
                'queue'    => 'create-export-file',
                'view_all' => 'view-all-export-file',
                'view_own' => 'view-own-export-file',
                'delete'   => 'delete-export-file',
            ],
            'pages' => [
                // Per-page ability overrides:
                // 'user' => ['export' => 'export-user', 'queue' => 'queue-user'],
            ],
        ],
    ],
];
```

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

## PDF Output

The default Blade view is `export::pdf.export`.

Publish to customise:

```bash
php artisan vendor:publish --tag=export-builder-views
```

Override per export class:

```php
public function pdfView(): string
{
    return 'exports.my-custom-template';
}

public function pdfData(): array
{
    return [
        'title'   => 'Users Report',
        'columns' => array_map(fn ($h) => ['label' => $h, 'width' => 'auto'], $this->headings()),
        'rows'    => $this->buildQuery()->get()->map(fn ($r) => array_values($this->map($r)))->toArray(),
    ];
}
```

**PDF settings resolver** — resolve dynamic settings (e.g. company logo from DB):

```php
// config/export.php
'pdf' => [
    'settings_resolver' => [App\Services\BrandSettings::class, 'forExport'],
    // Also supports: invokable class string, Closure
],
```

The resolver must return an array. Keys available in the Blade view as `$settings['logo_url']`, `$settings['company_name']`, etc.

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
DELETE api/export-log/{id}   ← soft-delete record and remove stored file
```

Files are stored on the configured disk:

```php
'storage' => ['disk' => 'local', 'path' => 'exports'],
```

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
| `DELETE` | `api/export-log/{id}` | `export-builder.export.logs.destroy` | Delete record and file |

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

Disabled by default. Enable and configure per-page abilities:

```php
'permissions' => [
    'enabled' => true,
    'abilities' => [
        'export'   => 'export',
        'queue'    => 'create-export-file',
        'view_all' => 'view-all-export-file',
        'view_own' => 'view-own-export-file',
        'delete'   => 'delete-export-file',
    ],
    'pages' => [
        'user' => [
            'export' => 'export-user',
            'queue'  => 'create-user-export',
        ],
    ],
],
```

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

**Published to:** `lang/vendor/export/{en,ar}/export.php`

| Key | English | Arabic |
|---|---|---|
| `id` | ID | المعرف |
| `name` | Name | الاسم |
| `email` | Email | البريد الإلكتروني |
| `is_active` | Active | نشط |
| `created_at` | Created At | تاريخ الإنشاء |
| `yes` | Yes | نعم |
| `no` | No | لا |

**Use your own translation file:**

```php
// config/export.php
'trans_file' => 'api',  // looks up lang/en/api.php keys
```

**Add custom column translations** — add keys to your published `lang/vendor/export/en/export.php`:

```php
return [
    // ...existing keys...
    'order_number' => 'Order #',
    'total_amount' => 'Total',
];
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
- Storage config SSOT via `storageDisk()` / `storagePath()`
- Architecture regression guards (SSOT fixes)
- Edge cases: morph, nested relations, `customWith`, `customSelect`, PDF resolver variants

---

## License

MIT © [Hasan Hawary](https://github.com/hasanhawary)
