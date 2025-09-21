# Export Builder

[![Latest Stable Version](https://img.shields.io/packagist/v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![Total Downloads](https://img.shields.io/packagist/dm/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![PHP Version](https://img.shields.io/packagist/php-v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Lightweight, framework-friendly export generation for Laravel powered by maatwebsite/excel. Define tiny export classes and trigger CSV/XLS/XLSX downloads with built-in filtering, relations, and smart value formatting.

---

## ✨ Why Export Builder?
- Zero boilerplate: focus on a simple config array, not Excel internals.
- Convention over configuration: resolves exports by page name and namespace.
- Powerful mapping: convert types (date, datetime, int, money, booleans), translate headings, and resolve enums.
- Relations aware: eager-load one/many relations, flatten nested data, count/list/concat children.
- Production-ready: safe file names, error logging, and HTTP responses that Just Work.

---

## 📦 Installation

```bash
composer require hasanhawary/export-builder
```

The package auto-discovers its service provider. Optionally publish the default configuration to config/export.php:

```bash
php artisan vendor:publish --tag=export-builder-config
```

Configuration option available in config/export.php:
- namespace: Default is `HasanHawary\\ExportBuilder\\Types`. Change it e.g. to `App\\Exports` to keep exports inside your app.

---

## ⚡ Quick Start

1) Create an export class under your namespace. The class name must be `{Page}Export` (StudlyCase of the `page` filter). Example: a User report export:

```php
namespace App\Exports;

use HasanHawary\ExportBuilder\Types\BaseExport;
use App\Models\User;
use App\Enum\User\UserGenderEnum;

class UserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        $config = [
            'model' => User::class,
            'columns' => [
                'id' => 'int',
                'name' => 'text',
                'email' => 'text',
                'phone' => 'text',
                'gender' => UserGenderEnum::class, // enum class with static resolve($value)
                'is_active' => 'boolean',
                'last_login' => 'datetime',
                'created_at' => 'datetime',
            ],
            'relations' => [
                'one' => [
                    // foreign_key => [ relationName => [ column => type ]]
                    'created_by' => ['creator' => ['name' => 'text', 'id' => 'int']],
                ],
                'many' => [
                    'count' => [],
                    'list' => [],
                    'concat' => ['roles' => ['display_name' => 'text']]
                ],
            ],
        ];

        parent::__construct($config, $filter);
    }
}
```

2) Trigger a download from a controller/route using the Facade (recommended):

```php
use HasanHawary\ExportBuilder\Facades\Export;

public function downloadUsers()
{
    $filter = [
        'page' => 'user',   // resolves to App\\Exports\\UserExport if config('export.namespace') = 'App\\\\Exports'
        'format' => 'xlsx', // csv | xlsx | xls (default: xlsx)
        // Optional
        // 'filename' => 'users_report',
        // 'timestamp' => '2025-09-21_120000',
    ];

    return (new ExportBuilder($filter))->response(); // BinaryFileResponse download
}

```

---

## 🔧 Configuration
Publish the config and point the namespace to your preferred location:

```php
// config/export.php
return [
    'namespace' => 'App\\Exports',
];
```

Now an incoming filter like ['page' => 'order'] will resolve to App\\Exports\\OrderExport.

---

## 🧠 Columns, Types, and Formatting
Declare the shape of your dataset via the columns map. BaseExport automatically converts values using the following types:
- text: raw text
- date: Y-m-d
- datetime: Y-m-d H:i:s
- array: array => "a , b , c"
- int, float, money: numeric formatting (money uses 2 decimals)
- bool/boolean: localized to api.yes/api.no
- classPath: turns a class path into its tail name and tries to translate it
- Enum classes: If a class is provided, and it has a static resolve($value) method, it will be used to map values

Headings are generated from your column keys and passed through the api.* translation domain when available.

You can also pick specific columns at runtime by passing filter['columns'] = ['id','name','creator.name'].

---

## 🤝 Relations (one & many) and Nested Data
- one: eager-loads a single related model and flattens its fields using the pattern relation_field.
- many: supports three useful shapes for collections:
  - count: include the total count of items
  - list: return an array of values (useful for JSON exports)
  - concat: a comma-separated string of values

You may also target nested attributes using dot notation directly in columns, e.g. 'creator.department.name' => 'text'. These are resolved automatically.

---

## 🔎 Filtering & Query Options
You can control the dataset via the filter array you pass to your export or endpoint:
- apply_date: boolean; when true, start/end will filter the date_column (default created_at)
- start, end: date boundaries (YYYY-MM-DD)
- search: full-text like filter across top-level column names
- conditions: array of [key, operation, value] triplets passed to where()
- order_by, order_dir: sorting (asc|desc)
- limit: cap the number of records (handy for previews/testing)
- type: optional segmenting helper used by BaseExport to include keys matching this string
- columns: explicit column whitelist as an array

You can change the date column by setting 'date_column' in your export config.

---

## 🔐 Permissions
Override isEnabled() in your export class to guard access with policies/permissions:

```php
public function isEnabled(): bool
{
    return auth()->user()?->can('export-users');
}
```

If it returns false, the request responds with 403.

---

## 🧾 File Names & Formats
- format: csv, xlsx, or xls (default xlsx)
- filename: base name; defaults to page
- timestamp: defaults to current Ymd_His
The final file name is slugified as {filename}_{timestamp}.{ext}.

---

## 🛠 Troubleshooting
- 422 Missing export page: provide filter['page'].
- 404 Export class not found: ensure the class name matches {Page}Export and the namespace is configured.
- 403 Forbidden: isEnabled() returned false.
- Corrupted/blank Excel: the package cleans output buffers before streaming to avoid Excel corruption issues.

---

## ✅ Version Support
- PHP: 8.0 – 8.5
- Laravel: 8 – 12

---

## 📚 Examples Cheat-Sheet
- Only last 7 days and search by email:
```php
['page' => 'user', 'apply_date' => true, 'start' => now()->subDays(7)->toDateString(), 'search' => '@example.com']
```
- Sort and limit:
```php
['page' => 'user', 'order_by' => 'created_at', 'order_dir' => 'desc', 'limit' => 500]
```
- Add conditions (status = active):
```php
['page' => 'user', 'conditions' => [ ['key' => 'status', 'operation' => '=', 'value' => 'active'] ]]
```

## ✅ Version Support

- **PHP**: 8.0 – 8.5
- **Laravel**: 8 – 12

---

## 📜 License

MIT © [Hasan Hawary](https://github.com/hasanhawary)

