# Export Builder

[![Latest Stable Version](https://img.shields.io/packagist/v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![Total Downloads](https://img.shields.io/packagist/dm/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![PHP Version](https://img.shields.io/packagist/php-v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Export Builder** is a robust, configuration-driven export engine for Laravel. It eliminates the need for repetitive boilerplate code when generating Excel and PDF exports. By defining your export structure as a simple configuration, the package handles complex Eloquent relations, polymorphic data, advanced filtering, and automatic formatting out of the box.

---

## ✨ Features

- **Zero Boilerplate**: Define your export structure in a simple configuration array.
- **Smart Auto-Detection**: Automatically resolves the correct export class based on the request parameters.
- **Relation Powerhouse**: Handles nested relations (one-to-one, one-to-many), polymorphic support, and aggregations (count, list, concat).
- **Advanced Filtering**: Built-in "search-like" filtering on main models and relations without manual `where` clauses.
- **Automatic Formatting**: Auto-converts types: dates, money, enums, booleans, and class paths.
- **PDF & Excel Ready**: One engine, multiple formats. Switch between XLSX, CSV, XLS, and PDF seamlessly.
- **Highly Extensible**: Register custom exporters, pages, and modules with ease.

---

## 📦 Installation

1. **Install via Composer**:
   ```bash
   composer require hasanhawary/export-builder
   ```

2. **Publish Configuration**:
   ```bash
   php artisan vendor:publish --tag=export-builder-config
   ```

---

## 🚀 How It Works Internally

The **Export Builder** works by bridging your request filters with a dedicated Export Class.

1. **Detection**: When you call `ExportBuilder`, it reads the `page` parameter from your input.
2. **Resolution**: It searches for a matching class in your configured namespace (e.g., `App\Tools\Export\UserExport` for `page=user`).
3. **Execution**: It instantiates the export class, applies filters, executes the query, maps the results, and returns a `BinaryFileResponse`.
4. **Format Handling**: Based on the `format` parameter (`xlsx`, `pdf`, etc.), it automatically routes the data through the appropriate engine (`maatwebsite/excel` or `mpdf`).

---

## 🛠 Setup & Usage

### 1. Define an Export Class
Create a class that extends `BaseExport`. By default, these live in `app/Tools/Export`.

```php
namespace App\Tools\Export;

use HasanHawary\ExportBuilder\BaseExport;
use App\Models\User;
use App\Enums\UserStatus;

class UserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        $config = [
            'model' => User::class,
            'columns' => [
                'id'         => 'int',
                'name'       => 'text',
                'email'      => 'text',
                'status'     => UserStatus::class, // Auto-resolves Enums with resolve() method
                'created_at' => 'datetime',
            ],
            'relations' => [
                'one' => [
                    'role_id' => ['role' => ['name' => 'text']],
                ],
                'many' => [
                    'count'  => ['posts'],
                    'concat' => ['tags' => ['name' => 'text']]
                ],
            ],
            'filter_relations' => [
                'many' => [
                    'roles' => ['relation' => 'roles', 'column' => 'id'],
                ]
            ]
        ];

        parent::__construct($config, $filter);
    }
}
```

### 2. Using the Export Builder from a Controller
You don't need to manually instantiate your export class. Use the `ExportBuilder` directly.

```php
use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Http\Request;

public function export(Request $request)
{
    // The package auto-detects the 'UserExport' class from 'page=user'
    // and generates the response based on 'format=xlsx' or 'format=pdf'
    return (new ExportBuilder($request->all()))->response();
}
```

---

## 💎 Automatic Export Generation

One of the most powerful features of this package is its **Zero-Implementation Exporting**:

* **Instant Formats**: Simply pass `format=xlsx` or `format=pdf` in your request. The package engine automatically handles the generation without requiring any additional code in your controller or export class.
* **Powered Internally**: Export generation is fully managed by the package's internal system.
* **Out of the Box**: High-quality PDF and Excel files are produced using optimized internal templates and drivers.

---

## ⚙️ Configuration

The `config/export.php` file allows you to customize the behavior:

```php
return [
    // Where your Export classes are located
    'namespace' => 'App\\Tools\\Export',

    // The translation file used for column headers
    'trans_file' => 'export',

    'pdf' => [
        'settings' => [
            'logo_url' => 'https://example.com/logo.png',
            'company_name' => 'My Company',
        ],
        // Dynamically resolve PDF settings (e.g., from DB)
        'settings_resolver' => [App\Services\ExportSettingService::class, 'resolve'],
    ],
];
```

---

## 🔍 Advanced Filtering & Options

### Available Methods & Supported Parameters

The `ExportBuilder` constructor accepts an array of filters (usually `$request->all()`). Key parameters include:

| Parameter | Type | Description |
|---|---|---|
| `page` | `string` | **Required**. The name of the export (e.g., `user`). Matches `UserExport`. |
| `format` | `string` | Export format: `xlsx` (default), `pdf`, `csv`, `xls`. |
| `filename` | `string` | Custom filename for the generated file. |
| `timestamp`| `string` | Custom timestamp to append to the filename. |
| `start` | `string` | Start date for automatic date filtering (on `created_at` by default). |
| `end` | `string` | End date for automatic date filtering. |
| `advanced` | `array` | Advanced filter objects (see below). |

### Advanced Filters
Pass an `advanced` array in your filters to perform complex queries:
```json
{
  "page": "user",
  "advanced": [
    { "key": "status", "value": [1, 2] },
    { "key": "roles", "value": 5 }
  ]
}
```

### Supported Column Types (Automatic Formatting)

| Type | Output Example |
|---|---|
| `text` | Raw value |
| `int` / `float` | Casted numeric |
| `money` | `1250.00` |
| `date` / `datetime` | `2024-05-10` / `2024-05-10 14:30:00` |
| `boolean` | Localized `Yes` / `No` |
| `array` | `Value A , Value B` |
| `classPath` | `User` (from `App\Models\User`) |
| `Enum::class` | Result of `Enum::resolve($value)` |

---

## 🧩 Customization & Registration

### How Custom Exporters are Registered
The package uses a dynamic resolution strategy. Simply create a class in your configured namespace following the naming convention: `{StudlyPageName}Export`.

- Page `user_profile` -> `UserProfileExport`
- Page `orders` -> `OrdersExport`

### Custom Relation Mapping
Use the `CustomRelationTrait` (included in `BaseExport`) for complex mapping:

```php
public function customRelations(): array
{
    return [
        'profile' => [
            'bio',
            'age' => fn($profile) => $profile->birthday?->age ?? 'N/A',
        ],
        'items' => ['product_name', 'price'],
    ];
}
```

### Custom PDF Views
Override `pdfView` and `pdfData` in your export class:
```php
public function pdfView(): string
{
    return 'exports.custom-user-report';
}

public function pdfData(): array
{
    return [
        'summary' => $this->calculateSummary(),
    ];
}
```

---

## ✅ Version Support
- **PHP**: 8.0 – 8.5
- **Laravel**: 8 – 12

---

## 📜 License
MIT © [Hasan Hawary](https://github.com/hasanhawary)

