# Changelog

All notable changes to this project will be documented in this file.  
Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) — [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

---

## [3.0.0] - 2026-11-10

> Upgrading from 2.x: read **Breaking Changes** below. Projects that override `ExportJobController` / `ExportToFile` with the 2.4.3 signatures keep working.

### Breaking Changes (check before upgrading)
- **Routes require login by default**: `routes.middleware` is now `['api', 'auth:sanctum']` (`['api', 'auth']` without Sanctum). A published config keeps its own middleware.
- **Delete moves to the trash**: `DELETE export-log/delete` keeps the file; `DELETE export-log/force-delete` removes record and file. Previously delete removed the file right away.
- **Ownership when permissions are disabled**: list, show, download and delete only reach the current user's own exports. Previously every user could reach every export. Guests only reach exports with no owner.

### Changed
- `storage.disk` now defaults to the app's default disk (`filesystems.default`) instead of `local`. An explicit value in a published config is still used. Existing records keep the disk they were stored on.
- Queued exports are stored as `{path}/{id}_{file-name}`; the download name is unchanged.
- `maatwebsite/excel` is limited to `^3.1` (the package did not work with 4.x).
- Config: `EXPORT_DISK`, `EXPORT_PATH`, `EXPORT_CHUNK_SIZE`, `EXPORT_PERMISSIONS`, new `module.queue` (`EXPORT_QUEUE_CONNECTION`, `EXPORT_QUEUE`).

### Fixed
- **Upgrade from 2.4.3 broke host apps (regression in 2.4.4 / 2.4.5)**: `ExportJobController::destroy()` lost its 2.4.3 signature, so a host controller overriding `destroy(Request, int $exportFile, ...)` failed with a PHP fatal error, and `DELETE export-log/{id}` was removed. Both are back; bulk delete is now `destroyMany()` on `DELETE export-log/delete`. Public method signatures that hosts override (`index`, `show`, `download`, `destroy`, `export`, job `handle`) are unchanged from 2.4.3 and covered by a compatibility test.
- Security: `error_message` in the API showed raw exception text (SQL, paths). Users now get a translated message; the raw text stays in the database and the log, and is shown when `app.debug` or `module.expose_errors` is on.
- Security: advanced filters accepted hidden model columns (`password`, `remember_token`), which allowed guessing their values. Hidden attributes are no longer filterable.
- PDF data is loaded in chunks keeping only row values (custom ordering kept), instead of every model and relation at once.
- Translation lookup is shared by `ExportHelper::resolveTrans()` and export headings (`ExportHelper::lookup()`).
- **Host helpers replaced (regression since 2.4.4)**: `wrapPaginate()` / `successResponse()` were loaded through composer "files", i.e. before the app's own helpers, so they replaced the app's versions in every listing of the app. They are now fallbacks loaded by the service provider only when the app does not define them; the export history builds its own 2.4.3-shaped response (`data` / `links` / `meta`).
- Boolean cells showed `export.yes` / `export.no` unless the app had published the translation files.
- `ExportBuilder` closed output buffers it did not own (`ob_end_clean()` + `ob_start()`); it now only clears the current buffer's content.
- Security: two queued exports of the same page in the same minute wrote to the same file, so one user could download another user's export.
- Security: `page` could instantiate any class in the export namespace ending in `Export`; it must now implement `BaseExportContract`.
- `columns.*` / `related.*` must be strings (nested arrays caused a 500).
- PDF downloads left two files in the temp folder per request; the queued job left its generated file behind.
- Downloading an export whose file is gone answered `500`; now `404`.
- `file_url` on disks that cannot build URLs (ftp, sftp) broke the whole history listing; it is now `null`.
- `ExportHelper::resolveTrans($key, $page, $lang)` changed the app locale for the rest of the request.
- `successResponse()` was only defined when `wrapPaginate()` was not; `wrapPaginate()` now ignores invalid `per_page` values, applies the resource when paginating, and metadata can no longer replace `data`.
- Permissions that are not created yet (spatie `PermissionDoesNotExist`) now return `403` instead of a `500` error.

### Added
- **File language**: `lang` on export requests (validated against `export.locales`; `AR`, `ar_EG`, `ar-eg` → `ar`; anything else `422`). The whole file is built in it, the app locale is restored after. Queued exports keep the requester's language. RTL locales get a right-to-left PDF and Excel sheet.
- **Redesigned PDF**: one language per file (titles, dates, labels; never package English in an Arabic file or the reverse), numbers and dates kept left-to-right inside Arabic, page numbers, repeated table header, automatic landscape for wide tables.
- **Branding per project**: `export.pdf.brand` (name — per language —, logo, primary color, font) from `.env` / config, overridable per tenant through `settings_resolver`; unsafe values ignored.
- `php artisan export:translations`: lists headings and titles missing a translation per locale, exits 1 when something is missing.
- Queued jobs run logged in as the requester (`module.queue.run_as_requester`), so exports scoped by `auth()->user()` behave like in the request.
- Failed queue jobs (timeout, killed worker, tries exhausted) mark their export `failed` through a `JobFailed` listener; exports no longer stay `processing` forever. Job errors are logged.
- `module.queue.timeout` / `tries` (`EXPORT_QUEUE_TIMEOUT`, `EXPORT_QUEUE_TRIES`); a subclass's own `$timeout` / `$tries` still win.
- Trash pruning: `module.trash.prune_after_days` (`EXPORT_TRASH_DAYS`) deletes old trashed exports and their files via `model:prune`, scheduled daily by the package. Off by default.
- Limits: `module.max_per_page` (500) caps `per_page`, `module.max_bulk_ids` (500) caps ids per bulk request (`422` above it).
- `Export::make($filters)->response()` through the facade.
- Trash: `POST export-log/restore`, `DELETE export-log/force-delete`, `?trashed=only|with` on the history list, abilities `restore-export-file` and `force-delete-export-file` (`restore`, `force_delete`; defaults apply to older published configs).
- `ExportFile::exportable()` now works: page slugs resolve to their export class's model, morph-map aliases and class names too; unresolvable types give `null`. Eager loading supported.
- `ExportBuilder::exportClassFor($page)` and `BaseExport::exportModelClass()`.
- `{exportFile}` route parameters only match numbers.
- Export permissions are registered with `hasanhawary/permission-manager` automatically (an `ExportFile` entry in `roles.additional_operations`, built from the `*-export-file` abilities). A project's own `ExportFile` entry wins; turn it off with `export.module.permissions.register => false`.
- Export history filters: `type` / `exportable_type`, `status`, `format`, `created_by`, `start` / `end` date range. Multiple values accepted as a comma-separated list or an array.

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
