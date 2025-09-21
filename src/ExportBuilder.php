<?php

namespace HasanHawary\ExportBuilder;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportBuilder
{
    /**
     * Default namespace fallback for export classes. Can be overridden via config('export.namespace').
     */
    public const DEFAULT_NAMESPACE = 'App\\Tools\\Export';

    public function __construct(public array $filter)
    {
        // Clean output buffers to avoid Excel corruption issues
        if (function_exists('ob_end_clean')) {
            @ob_end_clean();
        }
        if (function_exists('ob_start')) {
            @ob_start();
        }
    }

    /**
     * @return BinaryFileResponse
     */
    public function response(): BinaryFileResponse
    {
        $page = (string)($this->filter['page'] ?? '');
        if ($page === '') {
            abort(422, 'Missing export page.');
        }

        $class = $this->buildExportPath($page);
        if (!class_exists($class)) {
            abort(404);
        }

        try {
            $object = new $class($this->filter);
            abort_if(!$object->isEnabled(), 403);

            $format = strtolower((string)($this->filter['format'] ?? 'xlsx'));
            $ext = match ($format) {
                'csv' => 'csv',
                'xls' => 'xls',
                default => 'xlsx',
            };

            $excelFormat = match ($format) {
                'csv' => \Maatwebsite\Excel\Excel::CSV,
                'xls' => \Maatwebsite\Excel\Excel::XLS,
                default => \Maatwebsite\Excel\Excel::XLSX,
            };

            $baseName = (string)($this->filter['filename'] ?? $page);
            $timestamp = (string)($this->filter['timestamp'] ?? date('Ymd_His'));
            $fileName = Str::slug("{$baseName}_{$timestamp}") . ".{$ext}";

            return Excel::download($object, $fileName, $excelFormat);

        } catch (\Throwable $e) {
            Log::error('ExportBuilder: failed to generate response', [
                'page' => $page,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Build the export class path based on the page name and configured namespace.
     */
    protected function buildExportPath(string $page): string
    {
        $ns = (string)(config('export.namespace') ?? self::DEFAULT_NAMESPACE);
        return $ns . '\\' . Str::studly($page) . 'Export';
    }
}
