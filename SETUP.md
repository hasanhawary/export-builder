# Setup Guide for Export Builder

## 🎯 Quick Start

### Laravel 10+ Installation

The easiest way to use Export Builder is in a Laravel 10+ project:

```bash
composer require hasanhawary/export-builder
```

The package will auto-register through Laravel's service provider discovery.

### Publishing Assets (Optional)

```bash
# Publish configuration
php artisan vendor:publish --tag=export-builder-config

# Publish views for custom PDF templates
php artisan vendor:publish --tag=export-builder-views

# Publish language files
php artisan vendor:publish --tag=export-builder-lang
```

## 📋 Requirements

### Core Dependencies
- **PHP 8.0+** (8.0, 8.1, 8.2, 8.3+)
- **Laravel 10.x, 11.x, 12.x, or 13.x** (or any Laravel using compatible packages)

### Required Packages
- `illuminate/support` - for collection and string utilities
- `illuminate/database` - for Eloquent ORM
- `maatwebsite/excel` - for Excel/CSV export
- `mccarlosen/laravel-mpdf` - for PDF generation
- `nesbot/carbon` - for date/time handling
- `symfony/http-foundation` - for HTTP responses

All dependencies are automatically installed via Composer.

## 🔧 Configuration

Edit `config/export.php` to customize:

```php
return [
    // Namespace where your Export classes should be located
    'namespace' => 'App\\Tools\\Export',

    // Translation file for column headers
    'trans_file' => 'export',

    // PDF settings
    'pdf' => [
        'settings' => [
            'logo_url' => 'https://example.com/logo.png',
            'company_name' => 'Your Company',
        ],
        // OR use a settings resolver for dynamic configuration
        'settings_resolver' => [App\Services\ExportSettingService::class, 'resolve'],
    ],
];
```

## 📂 Directory Structure

After installation, create the following directory structure in your Laravel project:

```
app/
├── Tools/
│   └── Export/
│       ├── UserExport.php
│       ├── OrderExport.php
│       └── ...
```

## 🚀 Creating Your First Export

### 1. Create an Export Class

```php
<?php

namespace App\Tools\Export;

use HasanHawary\ExportBuilder\BaseExport;
use App\Models\User;

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
                'created_at' => 'datetime',
            ],
            'relations' => [
                'one' => [],
                'many' => [
                    'concat' => [],
                    'list'   => [],
                    'count'  => [],
                ],
            ],
        ];

        parent::__construct($config, $filter);
    }
}
```

### 2. Use in Your Controller

```php
<?php

namespace App\Http\Controllers;

use HasanHawary\ExportBuilder\ExportBuilder;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function export(Request $request)
    {
        return (new ExportBuilder($request->all()))->response();
    }
}
```

### 3. Call from Frontend

```html
<!-- Export as XLSX -->
<a href="/export?page=user&format=xlsx">Export to Excel</a>

<!-- Export as PDF -->
<a href="/export?page=user&format=pdf">Export to PDF</a>

<!-- Export with filters -->
<a href="/export?page=user&format=xlsx&start=2024-01-01&end=2024-12-31">
    Export (2024)
</a>
```

## 🎓 Advanced Usage

### Using Relations

```php
'relations' => [
    'one' => [
        'role_id' => ['role' => ['name' => 'text']]
    ],
    'many' => [
        'concat' => ['tags' => ['name' => 'text']],
        'list'   => ['posts' => ['title' => 'text', 'slug' => 'text']],
        'count'  => ['comments'],
    ],
]
```

### Custom Filtering

```php
'filter_relations' => [
    'many' => [
        'roles' => [
            'relation' => 'roles',
            'column' => 'id',
        ],
    ],
]
```

Then pass filters in request:

```json
{
    "page": "user",
    "advanced": [
        { "key": "status", "value": [1, 2] },
        { "key": "roles", "value": [5] }
    ]
}
```

### Custom Relations with Closures

```php
public function customRelations(): array
{
    return [
        'profile' => [
            'bio',
            'age' => fn($profile) => $profile->birthday?->age ?? 'N/A',
        ],
    ];
}
```

### Custom PDF View

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

## 🌐 Supported Formats

| Format | Extension | Method |
|--------|-----------|--------|
| Excel (XLSX) | `.xlsx` | `format=xlsx` (default) |
| Excel (XLS) | `.xls` | `format=xls` |
| CSV | `.csv` | `format=csv` |
| PDF | `.pdf` | `format=pdf` |

## 📊 Column Types

Export Builder automatically formats columns based on their type:

| Type | Format Example |
|------|-----------------|
| `text` | Raw value |
| `int` | `1250` |
| `float` | `1250.50` |
| `money` | `1250.00` |
| `date` | `2024-05-10` |
| `datetime` | `2024-05-10 14:30:00` |
| `boolean` | `Yes` / `No` (localized) |
| `array` | `Value A , Value B` |
| `classPath` | `User` (extracted from `\App\Models\User`) |
| `Enum::class` | Calls `Enum::resolve($value)` |

## 🔐 Security Notes

- Always validate user input in your export classes
- Use advanced filters to control which data can be exported
- Implement authorization checks in your controller
- Be aware of N+1 query issues; use eager loading appropriately

## 🆘 Troubleshooting

### PDF Generation Fails
- Ensure `mccarlosen/laravel-mpdf` is installed
- Check that your views directory exists and is readable
- Verify translation files are loaded

### Memory Issues with Large Exports
- Use chunking (already implemented in `array()` method)
- Optimize your Eloquent queries
- Consider adding pagination or splitting exports

### Missing Translations
- Run `php artisan vendor:publish --tag=export-builder-lang`
- Add custom translation keys to `resources/lang/vendor/export/export.php`

## 📝 License

This package is open-sourced software licensed under the MIT license.

