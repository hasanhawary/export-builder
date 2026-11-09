<?php

namespace HasanHawary\ExportBuilder;

use HasanHawary\ExportBuilder\Contracts\BaseExportContract;
use HasanHawary\ExportBuilder\Renderers\ExcelRenderer;
use HasanHawary\ExportBuilder\Renderers\PdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entry point for the Export Builder package.
 *
 * Resolves the export class from the 'page' filter, instantiates it,
 * and delegates rendering to PdfRenderer or ExcelRenderer.
 */
class ExportBuilder
{
    /**
     * Default namespace for export classes.
     * Override via config('export.namespace').
     */
    public const DEFAULT_NAMESPACE = 'App\\Tools\\Export';

    public function __construct(public array $filter)
    {
        // Drop stray output (whitespace, notices) that would corrupt the binary file.
        // Only the current buffer's content is cleared: buffers owned by the framework,
        // Octane or a test runner are never closed or added.
        if (ob_get_level() > 0) {
            @ob_clean();
        }
    }

    /**
     * Build an exporter for the given filters; also works through the facade:
     * Export::make(['page' => 'user', 'format' => 'xlsx'])->response()
     */
    public static function make(array $filter): static
    {
        return new static($filter);
    }

    /**
     * Resolve the export class, instantiate it, and return a binary file download response.
     *
     * The whole file (headings, yes/no values, dates, PDF direction) is built in the requested
     * 'lang' when it is supported, else in the current app locale. The app locale is restored after.
     */
    public function response(): BinaryFileResponse
    {
        $locale = self::normalizeLocale($this->filter['lang'] ?? null) ?? app()->getLocale();

        $previousApp    = app()->getLocale();
        $previousCarbon = Carbon::getLocale();

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            return $this->render();
        } finally {
            app()->setLocale($previousApp);
            Carbon::setLocale($previousCarbon);
        }
    }

    /**
     * Locales a request may ask for with 'lang' (config 'export.locales').
     *
     * @return list<string>
     */
    public static function supportedLocales(): array
    {
        $locales = array_map(
            fn ($locale) => strtolower(str_replace('_', '-', trim((string) $locale))),
            (array) (config('export.locales') ?: ['en', 'ar'])
        );

        return array_values(array_unique(array_filter($locales)));
    }

    /**
     * A supported locale for a requested 'lang', or null.
     * Case and separators are ignored ('AR', 'ar_EG', 'ar-eg'); a regional variant falls back
     * to its language ('ar-EG' → 'ar') when only the language is supported.
     */
    public static function normalizeLocale(mixed $lang): ?string
    {
        if (! is_string($lang)) {
            return null;
        }

        $locale = strtolower(str_replace('_', '-', trim($lang)));

        if ($locale === '' || ! preg_match('/^[a-z]{2,3}(-[a-z0-9]{1,8}){0,6}$/', $locale)) {
            return null;
        }

        $supported = self::supportedLocales();

        if (in_array($locale, $supported, true)) {
            return $locale;
        }

        $language = explode('-', $locale)[0];

        return in_array($language, $supported, true) ? $language : null;
    }

    /**
     * Whether a locale is written right-to-left (config 'export.rtl_locales').
     */
    public static function isRtl(?string $locale = null): bool
    {
        $language = explode('-', strtolower(str_replace('_', '-', (string) ($locale ?? app()->getLocale()))))[0];

        return in_array($language, (array) config('export.rtl_locales', ['ar', 'fa', 'he', 'ur']), true);
    }

    private function render(): BinaryFileResponse
    {
        $page = (string) ($this->filter['page'] ?? '');

        if ($page === '') {
            abort(422, 'Missing export page.');
        }

        $class = $this->buildExportPath($page);

        // "page" comes from the request: only real export classes may be instantiated.
        if (! self::isExportClass($class)) {
            abort(404, "Export class not found for page: {$page}");
        }

        try {
            $object = new $class($this->filter);

            abort_if(! $object->isEnabled(), 403);

            $format = strtolower((string) ($this->filter['format'] ?? 'xlsx'));

            return $format === 'pdf'
                ? (new PdfRenderer($this->filter))->render($object)
                : (new ExcelRenderer($this->filter))->render($object, $format);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            // Re-throw HTTP exceptions so Laravel renders the correct status code.
            // Wrapping abort(403) / abort(404) as RuntimeException would produce 500.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('ExportBuilder: failed to generate response', [
                'page'      => $page,
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Resolve the fully-qualified export class name from a page slug.
     * e.g. 'user' → 'App\Tools\Export\UserExport'
     */
    protected function buildExportPath(string $page): string
    {
        return self::exportClassName($page);
    }

    /**
     * The export class for a page, or null when there is no valid export class for it.
     * e.g. 'user' → 'App\Tools\Export\UserExport'
     */
    public static function exportClassFor(string $page): ?string
    {
        if ($page === '') {
            return null;
        }

        $class = self::exportClassName($page);

        return self::isExportClass($class) ? $class : null;
    }

    private static function exportClassName(string $page): string
    {
        $ns = (string) (config('export.namespace') ?: self::DEFAULT_NAMESPACE);

        return $ns . '\\' . Str::studly($page) . 'Export';
    }

    private static function isExportClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, BaseExportContract::class);
    }

    /**
     * Build a slugified filename for an export file.
     * Single source of truth — used by ExportBuilder, PdfRenderer, ExcelRenderer, and ExportToFile.
     */
    public static function buildFileName(array $filters, string $format): string
    {
        $base      = (string) ($filters['filename'] ?? $filters['page'] ?? 'export');
        $timestamp = (string) ($filters['timestamp'] ?? now()->format('Y_m_d_H_i'));

        return Str::slug("{$base}_{$timestamp}") . '.' . self::extensionForFormat($format);
    }

    /**
     * Resolve the file extension for a given format string.
     * Single source of truth for format → extension mapping.
     */
    public static function extensionForFormat(string $format): string
    {
        return match (strtolower($format)) {
            'pdf'   => 'pdf',
            'csv'   => 'csv',
            'xls'   => 'xls',
            default => 'xlsx',
        };
    }
}
