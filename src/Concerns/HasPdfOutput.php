<?php

namespace HasanHawary\ExportBuilder\Concerns;

use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Support\Str;

/**
 * Provides default PDF view resolution and PDF data preparation for BaseExport.
 *
 * Override pdfView() to return a custom Blade view name.
 * Override pdfData() to return custom data for that view.
 *
 * All translation keys in pdfData() are resolved dynamically using the
 * configured trans_file — no hardcoded translation namespaces here.
 */
trait HasPdfOutput
{
    /**
     * The Blade view used to render the PDF.
     * Override in your export class to use a project-specific template.
     */
    public function pdfView(): string
    {
        return 'export::pdf.export';
    }

    /**
     * Build the data array passed to the PDF Blade view.
     *
     * The default implementation:
     *  - Derives a human-readable title from the export class name
     *    (e.g. UserExport → "Users", TicketExport → "Tickets")
     *  - Checks for a matching translation key via resolveTrans()
     *    using the configured trans_file (e.g. export.users_title)
     *  - Passes trans_file to the Blade view so it can build
     *    translation keys dynamically (date_range, from, to, etc.)
     */
    public function pdfData(): array
    {
        // Load in chunks (with eager loads, keeping any custom order) and keep only the row
        // values, never every model + relation in memory at once. mPDF still needs the full HTML,
        // so very large datasets are better exported as xlsx / csv.
        $rows = [];
        (clone $this->buildQuery())->chunk(max(1, (int) config('export.chunk_size', 500)), function ($records) use (&$rows): void {
            foreach ($records as $record) {
                $rows[] = array_values($this->map($record));
            }
        });

        return [
            'title'   => $this->pdfTitle(),
            'columns' => array_map(fn ($h) => ['label' => $h, 'width' => 'auto'], $this->headings()),
            'rows'    => $rows,
        ];
    }

    /**
     * The PDF title, always in the file's language:
     *  1. the name the user gave the export ('filename')
     *  2. a "{type}_title" translation (e.g. users_title), then a "{type}" translation (users)
     *  3. English only: the name derived from the class (UserExport → "Users")
     *  4. otherwise the translated generic "Export Report" — never English text in another language
     */
    /**
     * Translation keys tried for the title, in order (also used by `php artisan export:translations`):
     * "{type}_title", "{type}" (UserExport → users) and "pages.{page}" (page = user).
     *
     * @return list<string>
     */
    public function titleTranslationKeys(): array
    {
        $base = Str::snake(Str::replaceLast('Export', '', class_basename(static::class)));
        $type = Str::plural($base);
        $page = (string) ($this->filter['page'] ?? $base);

        return array_values(array_unique(["{$type}_title", $type, "pages.{$page}"]));
    }

    /**
     * The first title translation found in the configured file, then the package's 'export' file.
     */
    public function translatedTitle(?string $file = null, ?string $locale = null): ?string
    {
        $file ??= (string) config('export.trans_file', 'export');

        foreach (array_unique([$file, 'export']) as $source) {
            foreach ($this->titleTranslationKeys() as $key) {
                if (($translated = ExportHelper::lookup($key, $source, $locale)) !== null) {
                    return $translated;
                }
            }
        }

        return null;
    }

    protected function pdfTitle(): string
    {
        $filename = trim((string) ($this->filter['filename'] ?? ''));

        if ($filename !== '') {
            return $filename;
        }

        $type = Str::plural(Str::snake(Str::replaceLast('Export', '', class_basename(static::class))));
        $file = (string) config('export.trans_file', 'export');

        if (($translated = $this->translatedTitle($file)) !== null) {
            return $translated;
        }

        if (str_starts_with(strtolower(app()->getLocale()), 'en')) {
            return Str::title(str_replace('_', ' ', $type));
        }

        return ExportHelper::lookup('export_report', $file)
            ?? ExportHelper::lookup('export_report', 'export')
            ?? Str::title(str_replace('_', ' ', $type));
    }
}
