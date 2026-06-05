# Changelog

All notable changes to this project will be documented in this file.  
Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) — [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

---

## [2.0.0] - 2026-06-05

### Breaking Changes
- `BaseExport` now implements `FromCollection` instead of `FromArray` — `array()` method replaced by `collection()` returning a `LazyCollection`
- `BaseExport` now also implements `BaseExportContract` interface
- `headings()` no longer mutates instance state — previously calling `headings()` before `map()` would destroy the column config
- Package routes now default to `api/` prefix instead of `ai-chat/`
- `related_type` default is no longer injected by the package controllers — host export classes own their own defaults

### Added
- **`Contracts/BaseExportContract`** — interface for type-hinting custom export implementations
- **`Renderers/PdfRenderer`** and **`Renderers/ExcelRenderer`** — rendering extracted from `ExportBuilder` into dedicated single-responsibility classes
- **`Support/ExportHelper`** — static utility class replacing global functions `eb_isArrayIndex()` / `eb_resolveTrans()`; globals kept as thin backwards-compatible aliases
- **`ExportPermissionResolver::canList()`** — single owner of list permission check; `index` no longer has inline permission logic
- **`ExportPermissionResolver::scopeForUser()`** — single owner of query scoping; custom resolver overrides now honored on all endpoints including `index`
- **`ExportPermissionResolver::check()`** private guard — eliminates repeated enabled + resolveUser + null-check pattern across all public methods
- **`ExportFileService::storageDisk()`** and **`storagePath()`** — single source of truth for storage config; eliminates 5× scattered `config()` reads
- **`ExportBuilder::buildFileName()`** static method — single source of truth for filename generation; used by both direct response and queued job
- **`ExportBuilder::extensionForFormat()`** static method — single source of truth for format→extension mapping
- **`buildQuery()` instance cache** — assembled once per export instance; both `collection()` and `pdfData()` share the same builder
- **`detectForeignKeys()` instance cache** — relation reflection computed once per instance
- **`getAllowedColumns()` static cache** — one `SHOW COLUMNS` query per table per request
- **`resolvePdfSettings()` instance cache** — resolver invoked once per `ExportBuilder` instance
- **`config('export.chunk_size')`** — configurable `lazyById()` chunk size (default 500)
- **Memory-safe streaming** — `collection()` uses `lazyById()` keyset pagination instead of `cursor()` unbuffered query or in-memory accumulation
- **Translation files** — `resources/lang/en/export.php` and `resources/lang/ar/export.php` with common column headings and bool values
- **Arabic translation** — full `ar/` lang folder (`export.php`, `api.php`, `pdf.php`)
- **`resolveTrans()` namespace fix** — correctly resolves `export::export.{key}` for package translations; no longer requires host app to have matching flat keys
- **121 tests, 257 assertions** covering all features including morph relations, nested relations, `customWith`, `customSelect`, PDF resolver variants, chunk size, query cache, SSOT architecture guards

### Fixed
- `abort()` / `abort_if()` inside `ExportBuilder::response()` try-block were swallowed as `RuntimeException` (500 response) — HTTP exceptions are now re-thrown with original status code
- PDF + column filter bug — `headings()` previously mutated `$this->columns`, causing `buildQuery()` to run with a filtered column set when called after `headings()`; both methods now work on local copies and are order-independent
- `map()` was not applying column/related filters consistently — now applies `applyColumnFilter()` to all relation groups (list, count, additionalQuery) for consistency with `headings()`
- `ExportJobController::index()` was bypassing `ExportPermissionResolver` with inline config reads — custom resolver overrides were silently ignored on the list endpoint
- `customWith` was added to the Eloquent query twice in `buildQuery()` — harmless but confusing; second call removed
- `helpers.php` was missing `use Illuminate\Support\Str` — `eb_resolveTrans` would fatal at runtime in environments where the global wasn't already autoloaded
- `isEnabled()` return type was `true` (PHP 8.2+ only literal type) — changed to `bool` for PHP 8.1 compatibility
- Leftover `// dd($class)` debug comment removed from `ExportBuilder`
- `export-builder-assets` publish tag removed — previously published `resources/` to `resource_path()` which would overwrite the host app's views and lang directories

### Changed
- `ExportBuilder::response()` is now leaner — delegates to `PdfRenderer` / `ExcelRenderer`
- `ExportBuilderServiceProvider` binding for `ExportBuilder` now accepts `['filter' => $filters]` via `makeWith()` instead of always binding with empty filter
- Package default route prefix changed from `ai-chat` to `api`
- `ExportRoutes::routePrefix()` fallback updated to `api`

---

## [1.0.0] - 2024-06-04

### Added
- Initial stable release
- Support for Laravel 10, 11, 12, 13
- Support for PHP 8.0+
- Excel export (XLSX, XLS, CSV) via `maatwebsite/excel`
- PDF export via `carlos-meneses/laravel-mpdf` with customisable Blade template
- Advanced filtering — `whereIn`, `whereHas`, morph constraints, enum resolvers
- Custom relation mapping via `CustomRelationTrait`
- Safe route registration — host routes always win on URI/name conflict
- Service Provider with auto-discovery
- Publishable config, views, lang files, migrations
- PHPUnit test suite
