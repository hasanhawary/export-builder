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

        $safeFilter = $this->filter;
        unset($safeFilter['columns']);

        $locale = app()->getLocale();
        $isRtl  = ExportBuilder::isRtl($locale);

        $viewData = array_merge($data, $safeFilter, [
            'data'        => $data,
            'start'       => ! empty($this->filter['start']) ? Carbon::parse($this->filter['start']) : null,
            'end'         => ! empty($this->filter['end'])   ? Carbon::parse($this->filter['end'])   : null,
            'settings'    => $settings,
            // Fully-resolved translation prefix for the Blade view.
            // e.g. trans_file='export' → trans_prefix='export::export'
            // e.g. trans_file='api'    → trans_prefix='api'
            'trans_prefix' => $this->resolveTransPrefix(),
            'locale'       => $locale,
            'is_rtl'       => $isRtl,
            'brand'        => $brand = $this->brand($settings, $locale),
            'generated_at' => Carbon::now(),
            'generated_by' => $this->generatedBy(),
        ]);

        $html    = view($viewName, $viewData)->render();
        $htmlLen = strlen($html);

        // mPDF uses PCRE internally — raise the backtrack limit for large HTML
        if ($htmlLen > (int) ini_get('pcre.backtrack_limit')) {
            ini_set('pcre.backtrack_limit', $htmlLen * 2);
        }

        $pdf      = PDF::loadHTML($html, $this->mpdfConfig(count($data['columns'] ?? []), $isRtl, $brand['font']));
        $fileName = ExportBuilder::buildFileName($this->filter, 'pdf');

        // Write into the file tempnam() created (appending ".pdf" used to leave that file behind),
        // and delete it once the response has been sent.
        $tempFile = tempnam(sys_get_temp_dir(), 'eb_pdf_');
        file_put_contents($tempFile, $pdf->output());

        return (new BinaryFileResponse($tempFile, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]))->deleteFileAfterSend(true);
    }

    /**
     * Resolve PDF settings from config, optionally via a configured resolver callable.
     * The result is memoized on the instance.
     */
    private ?array $resolvedSettings = null;

    /**
     * Resolve the fully-qualified translation prefix for the Blade view.
     *
     * - trans_file = 'export' → 'export::export'  (package namespace)
     * - trans_file = 'api'    → 'api'              (host-app flat file)
     */
    private function resolveTransPrefix(): string
    {
        $transFile = config('export.trans_file', 'export');

        // Package namespace: single-word, no '::' or '.'
        if (! str_contains($transFile, '::') && ! str_contains($transFile, '.')) {
            return "{$transFile}::{$transFile}";
        }

        return $transFile;
    }

    /**
     * mPDF options: right-to-left layout, Arabic shaping and fonts per script, and the page
     * orientation ('export.pdf.orientation': auto | portrait | landscape; auto = landscape
     * from 'export.pdf.landscape_from_columns' columns). 'export.pdf.mpdf' is merged last.
     */
    /**
     * The project's identity on the PDF: config 'export.pdf.brand', overridden by the legacy
     * 'settings' keys (company_name, logo_url, primary_color) and the settings_resolver result.
     * The name can be one string or one per locale (['ar' => '...', 'en' => '...']); it is only
     * shown when set for this file's language, so an Arabic PDF never gets an English name.
     *
     * @return array{name: ?string, logo: ?string, primary_color: string, font: string}
     */
    private function brand(array $settings, string $locale): array
    {
        $config = (array) config('export.pdf.brand', []);

        $name  = $settings['company_name'] ?? $settings['brand_name'] ?? $config['name'] ?? null;
        $logo  = $settings['logo_url'] ?? $settings['logo'] ?? $config['logo'] ?? null;
        $color = (string) ($settings['primary_color'] ?? $config['primary_color'] ?? '');
        $font  = (string) ($settings['font'] ?? $config['font'] ?? '');

        return [
            'name'          => $this->forLocale($name, $locale),
            'logo'          => $this->logoSource($logo),
            'primary_color' => preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) ? $color : '#1e3a5f',
            'font'          => preg_match('/^[a-zA-Z0-9_-]+$/', $font) ? strtolower($font) : 'dejavusanscondensed',
        ];
    }

    private function forLocale(mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value[explode('-', $locale)[0]] ?? null;
        }

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * A logo mPDF can load: http(s) / data URI, an existing absolute path, or a path in public/.
     */
    private function logoSource(mixed $logo): ?string
    {
        if (! is_string($logo) || trim($logo) === '') {
            return null;
        }

        $logo = trim($logo);

        if (preg_match('#^(https?://|data:image/)#i', $logo)) {
            return $logo;
        }

        foreach ([$logo, public_path(ltrim($logo, '/\\'))] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function mpdfConfig(int $columns, bool $isRtl, string $font = 'dejavusanscondensed'): array
    {
        $orientation = strtolower((string) config('export.pdf.orientation', 'auto'));

        $landscape = match ($orientation) {
            'landscape', 'l' => true,
            'portrait', 'p'  => false,
            default          => $columns >= max(1, (int) config('export.pdf.landscape_from_columns', 8)),
        };

        return array_merge([
            'format'            => $landscape ? 'A4-L' : 'A4',
            'orientation'       => $landscape ? 'L' : 'P',
            'default_font'      => $font,
            'directionality'    => $isRtl ? 'rtl' : 'ltr',
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
            'autoArabic'        => true,
        ], (array) config('export.pdf.mpdf', []));
    }

    private function generatedBy(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $name = data_get($user, 'name') ?? data_get($user, 'full_name') ?? data_get($user, 'email');

        return is_scalar($name) && (string) $name !== '' ? (string) $name : null;
    }

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
