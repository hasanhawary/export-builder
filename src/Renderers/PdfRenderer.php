<?php

namespace HasanHawary\ExportBuilder\Renderers;

use Carbon\Carbon;
use HasanHawary\ExportBuilder\Contracts\BaseExportContract;
use HasanHawary\ExportBuilder\ExportBuilder;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf as PDF;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders a BaseExport instance to a PDF binary file response.
 *
 * Owned by ExportBuilder — extracted here to keep ExportBuilder focused
 * on class resolution and dispatch, not on rendering mechanics.
 */
final class PdfRenderer
{
    public function __construct(private readonly array $filter) {}

    /**
     * Render the export object to a downloadable PDF response.
     *
     * @throws RuntimeException if the export object does not implement pdfView()
     */
    public function render(BaseExportContract $exportObject): BinaryFileResponse
    {
        $data     = $exportObject->pdfData();
        $viewName = $exportObject->pdfView();
        $settings = $this->resolveSettings();

        $viewData = array_merge($data, $this->filter, [
            'data'     => $data,
            'start'    => ! empty($this->filter['start']) ? Carbon::parse($this->filter['start']) : null,
            'end'      => ! empty($this->filter['end'])   ? Carbon::parse($this->filter['end'])   : null,
            'settings' => $settings,
        ]);

        $html    = view($viewName, $viewData)->render();
        $htmlLen = strlen($html);

        // mPDF uses PCRE internally — raise the backtrack limit for large HTML
        if ($htmlLen > (int) ini_get('pcre.backtrack_limit')) {
            ini_set('pcre.backtrack_limit', $htmlLen * 2);
        }

        $pdf      = PDF::loadHTML($html);
        $fileName = ExportBuilder::buildFileName($this->filter, 'pdf');

        $tempFile = tempnam(sys_get_temp_dir(), 'eb_pdf_') . '.pdf';
        file_put_contents($tempFile, $pdf->output());

        return new BinaryFileResponse($tempFile, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    /**
     * Resolve PDF settings from config, optionally via a configured resolver callable.
     * The result is memoized on the instance.
     */
    private ?array $resolvedSettings = null;

    private function resolveSettings(): array
    {
        if ($this->resolvedSettings !== null) {
            return $this->resolvedSettings;
        }

        $settings = config('export.pdf.settings', []);
        $resolver = config('export.pdf.settings_resolver');

        if (empty($resolver)) {
            return $this->resolvedSettings = is_array($settings) ? $settings : [];
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
            $target   = is_string($resolver[0]) ? app($resolver[0]) : $resolver[0];
            $resolved = app()->call([$target, $resolver[1]]);
        }

        $base = is_array($settings) ? $settings : [];

        return $this->resolvedSettings = is_array($resolved)
            ? array_merge($base, $resolved)
            : $base;
    }
}
