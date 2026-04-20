<?php

namespace HasanHawary\ExportBuilder;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;
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
        $page = (string) ($this->filter['page'] ?? '');
        if ($page === '') {
            abort(422, 'Missing export page.');
        }

        $class = $this->buildExportPath($page);
        // dd($class);
        if (!class_exists($class)) {
            abort(404);
        }

        try {
            $object = new $class($this->filter);
            abort_if(!$object->isEnabled(), 403);

            $format = strtolower((string) ($this->filter['format'] ?? 'xlsx'));

            if ($format === 'pdf') {
                return $this->generatePdfResponse($object, $page);
            }

            return $this->generateExcelResponse($object, $page, $format);
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
     * Undocumented function
     *
     * @param [type] $exportObject
     * @param string $page
     * @return BinaryFileResponse
     */
    private function generatePdfResponse($exportObject, string $page): BinaryFileResponse
    {
        if (!method_exists($exportObject, 'pdfView')) {
            throw new RuntimeException('Export class must implement pdfView() method for PDF export');
        }

        $data = method_exists($exportObject, 'pdfData') ? $exportObject->pdfData() : [];
        $viewName = $exportObject->pdfView();
        $settings = $this->resolvePdfSettings();

        $dataKey = method_exists($exportObject, 'pdfDataKey') ? $exportObject->pdfDataKey() : 'data';

        $pdfData = array_merge(
            $this->filter,
            [
                $dataKey     => $data,
                'data'       => $data,
                'start' => !empty($this->filter['start']) ? Carbon::parse($this->filter['start']) : null,
                'end'   => !empty($this->filter['end'])   ? Carbon::parse($this->filter['end'])   : null,
                'settings'   => $settings,
            ]
        );

        // Render blade to HTML string first so we can measure its size
        $html = view($viewName, $pdfData)->render();

        // mPDF uses PCRE regex internally to parse HTML.
        // When HTML exceeds pcre.backtrack_limit it throws the "larger than pcre.backtrack_limit" error.
        // We raise the limit dynamically to double the HTML size before creating the PDF.
        $htmlLen = strlen($html);
        if ($htmlLen > (int) ini_get('pcre.backtrack_limit')) {
            ini_set('pcre.backtrack_limit', $htmlLen * 2);
        }

        $pdf = PDF::loadHTML($html);

        $baseName = (string) ($this->filter['filename'] ?? $page);
        $timestamp = (string) ($this->filter['timestamp'] ?? date('Ymd_His'));
        $fileName = Str::slug("{$baseName}_{$timestamp}") . '.pdf';

        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
        file_put_contents($tempFile, $pdf->output());

        return new BinaryFileResponse($tempFile, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * Resolve PDF settings using the configured resolver.
     *
     * @return array
     */
    private function resolvePdfSettings(): array
    {
        $settings = config('export.pdf.settings', []);

        $resolver = config('export.pdf.settings_resolver');

        if (empty($resolver)) {
            return is_array($settings) ? $settings : [];
        }

        $resolved = null;

        if (is_callable($resolver)) {
            $resolved = app()->call($resolver);
        } elseif (is_string($resolver) && class_exists($resolver)) {
            $instance = app($resolver);

            if (is_callable($instance)) {
                $resolved = $instance();
            }
        } elseif (is_array($resolver) && count($resolver) === 2) {
            $target = is_string($resolver[0]) ? app($resolver[0]) : $resolver[0];
            $method = $resolver[1];

            $resolved = app()->call([$target, $method]);
        }

        if (is_array($resolved)) {
            return array_merge(
                is_array($settings) ? $settings : [],
                $resolved
            );
        }

        return is_array($settings) ? $settings : [];
    }

    /**
     * Undocumented function
     *
     * @param [type] $exportObject
     * @param string $page
     * @param string $format
     * @return BinaryFileResponse
     */
    private function generateExcelResponse($exportObject, string $page, string $format): BinaryFileResponse
    {
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

        $baseName = (string) ($this->filter['filename'] ?? $page);
        $timestamp = (string) ($this->filter['timestamp'] ?? date('Ymd_His'));
        $fileName = Str::slug("{$baseName}_{$timestamp}") . ".{$ext}";

        return Excel::download($exportObject, $fileName, $excelFormat);
    }

    /**
     * Build the export class path based on the page name and configured namespace.
     */
    protected function buildExportPath(string $page): string
    {
        $ns = (string) (config('export.namespace') ?? self::DEFAULT_NAMESPACE);
        return $ns . '\\' . Str::studly($page) . 'Export';
    }
}