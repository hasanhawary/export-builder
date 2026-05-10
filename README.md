# Export Builder

[![Latest Stable Version](https://img.shields.io/packagist/v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![Total Downloads](https://img.shields.io/packagist/dm/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![PHP Version](https://img.shields.io/packagist/php-v/hasanhawary/export-builder.svg)](https://packagist.org/packages/hasanhawary/export-builder)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Export Builder** is a powerful, configuration-driven export engine for Laravel. Stop writing boilerplate Excel logic and start defining exports as simple, reusable configurations. Powered by `maatwebsite/excel` and `mpdf`, it handles complex Eloquent relations, polymorphic data, and advanced filtering with ease.

---

## ✨ Why Export Builder?
- **Zero Boilerplate**: Define your export structure in a simple `$config` array.
- **Relation Powerhouse**: Deeply nested relations, polymorphic support, and collection aggregations (count, list, concat) out of the box.
- **Advanced Filtering**: Complex "search-like" filtering on main models and relations without writing a single `where` clause.
- **Smart Formatting**: Automatic type conversion for dates, money, enums, booleans, and class paths.
- **PDF & Excel**: Generate beautiful PDF reports or chunked Excel downloads using the same logic.
- **Production Ready**: Built-in chunked processing for large datasets to keep memory usage low.

---

## 📦 Installation

```bash
composer require hasanhawary/export-builder
```

Publish the configuration:
```bash
php artisan vendor:publish --tag=export-builder-config
```

---

## ⚡ Quick Start

1) Define an export class (e.g., `App\Tools\Export\UserExport`):

```php
class UserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        $config = [
            'model' => User::class,
            'columns' => [
                'id' => 'int',
                'name' => 'text',
                'status' => UserStatusEnum::class, // Auto-resolves via Enum::resolve()
                'created_at' => 'datetime',
            ],
            'relations' => [
                'one' => [
                    'role_id' => ['role' => ['name' => 'text']],
                ],
                'many' => [
                    'count' => ['posts'],
                    'concat' => ['tags' => ['name' => 'text']]
                ],
            ],
        ];

        parent::__construct($config, $filter);
    }
}
```

2) Trigger from your controller:

```php
return Export::download([
    'page' => 'user',
    'format' => 'xlsx',
    'start' => '2024-01-01',
    'end' => '2024-12-31'
]);
```

---

## 🚀 The Power of Advanced Filtering

The `AdvancedFilter` trait allows you to perform complex queries through a simple JSON/Array payload. This is perfect for dynamic data tables or admin dashboards.

### 🛠 Configuration
Define how incoming keys map to your database relations in your export class:

```php
'filter_relations' => [
    'many' => [
        // Filter by related IDs
        'roles' => ['relation' => 'roles', 'column' => 'id'],
        
        // Filter through a nested relation
        'country' => [
            'relation' => 'profile.city.country',
            'column' => 'id'
        ],
        
        // Polymorphic filtering!
        'tags' => [
            'relation' => 'taggables',
            'morph' => 'taggable',
            'morph_types' => [Post::class, Video::class],
            'column' => 'tag_id'
        ]
    ]
]
```

### 📡 Usage
Pass an `advanced` array in your request:

```json
{
  "page": "user",
  "advanced": [
    { "key": "roles", "value": [1, 5] },
    { "key": "country", "value": 10 },
    { "key": "name", "value": "John" } // Matches column name automatically
  ]
}
```

---

## 📄 PDF Generation
Generate reports using Blade templates. Your class just needs to point to a view:

```php
public function pdfView(): string {
    return 'exports.users_pdf';
}
```

The view automatically receives:
- `$data`: The prepared collection.
- `$start` & `$end`: Carbon instances of the date range.
- `$settings`: Global PDF settings from `config/export.php`.

---

## 🧠 Data Types & Formatting

| Type | Output Example |
|---|---|
| `text` | Raw value |
| `int` / `float` | Casted numeric |
| `money` | `1,250.00` |
| `date` / `datetime` | `2024-05-10` / `2024-05-10 14:30:00` |
| `boolean` | Localized `Yes` / `No` |
| `array` | `Value A , Value B` |
| `classPath` | `User` (from `App\Models\User`) |
| `Enum::class` | Result of `Enum::resolve($value)` |

---

## 🛠 Advanced Customization

- **Custom Query**: Use `additionalQuery` to add closures to the base query.
- **Custom Relation Mapping**: Use `CustomRelationTrait` to define multiple columns for a single relation automatically.
- **Eager Loading**: Use `customWith` for specific performance optimizations.
- **Select Specifics**: Use `customSelect` to reduce database load.

---

### 🛠 Custom Relation Mapping

The `CustomRelationTrait` is a powerful way to define relations with zero boilerplate. It is now **integrated by default** in `BaseExport`, allowing you to handle complex mappings by simply defining `customRelations()` in your export class.

#### 🛠 Usage

Since the trait is already included in `BaseExport`, you only need to implement the mapping method:

```php
class OrderExport extends BaseExport
{
    public function __construct(array $filter)
    {
        $config = [
            'model' => Order::class,
            'columns' => [
                'id' => 'int',
                'reference' => 'text'
            ],
        ];
        parent::__construct($config, $filter);
    }

    /**
     * Define relations and the attributes you want to export.
     * This is the recommended way to handle relations.
     */
    public function customRelations(): array
    {
        return [
            // Standard mapping: relation_name => [attributes]
            'user' => ['name', 'email'], 
            
            // Advanced mapping with closures & custom aliases
            'profile' => [
                'bio',
                'age' => fn($profile) => $profile->birthday?->age ?? 'N/A',
                'full_location' => fn($profile) => "{$profile->city}, {$profile->country}"
            ],
            
            // One-to-Many support
            'items' => ['product_name', 'price'],
            
            // Nested relations (dots are converted to underscores in column names)
            'shippingAddress.city' => ['name'],
        ];
    }
}
```

#### 📊 Output Behavior

| Relation Type | Mapping Logic | Example Column Name |
|---|---|---|
| **One-to-One** | `relation_attribute` | `user_name`, `profile_bio` |
| **Custom Alias** | `relation_key` | `profile_age`, `profile_full_location` |
| **One-to-Many** | `relation_index_attribute` | `items_0_product_name`, `items_1_price` |
| **Nested** | `path_attribute` (dots → `_`) | `shippingAddress_city_name` |

#### 💡 Why use CustomRelationTrait?
- **Zero Configuration**: No need to manually add the trait; it's part of the base class.
- **Closure Support**: Perform complex formatting directly in the mapping.
- **Automatic Eager Loading**: Relations are automatically detected and eager-loaded.
- **Foreign Key Detection**: Automatically includes required FKs (like `user_id`) in `customSelect`.
- **Automatic Cleanup**: Strips HTML tags and removes redundant `_id` columns from the main model output.
- **Smart Headings**: Generates and translates headings automatically.

---

## ✅ Version Support
- **PHP**: 8.0 – 8.5
- **Laravel**: 8 – 12

---

## 📜 License
MIT © [Hasan Hawary](https://github.com/hasanhawary)

