<?php

namespace HasanHawary\ExportBuilder;

use HasanHawary\ExportBuilder\Renderers\ExcelRenderer;
use HasanHawary\ExportBuilder\Renderers\PdfRenderer;
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
        // Clean output buffers to avoid Excel file corruption
        if (function_exists('ob_end_clean')) {
            @ob_end_clean();
        }
        if (function_exists('ob_start')) {
            @ob_start();
        }
    }

    /**
     * Resolve the export class, instantiate it, and return a binary file download response.
     */
    public function response(): BinaryFileResponse
    {
        $page = (string) ($this->filter['page'] ?? '');

        if ($page === '') {
            abort(422, 'Missing export page.');
        }

        $class = $this->buildExportPath($page);

        if (! class_exists($class)) {
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
        $ns = (string) (config('export.namespace') ?: self::DEFAULT_NAMESPACE);

        return $ns . '\\' . Str::studly($page) . 'Export';
    }

    /**
     * Build a slugified filename for an export file.
     * Single source of truth — used by ExportBuilder, PdfRenderer, ExcelRenderer, and ExportToFile.
     */
    public static function buildFileName(array $filters, string $format): string
    {
        $base      = (string) ($filters['filename'] ?? $filters['page'] ?? 'export');
        $timestamp = (string) ($filters['timestamp'] ?? now()->format('Ymd_His'));

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
