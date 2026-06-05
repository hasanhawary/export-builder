<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Provides default PDF view resolution and PDF data preparation for BaseExport.
 *
 * Override pdfView() to return a custom Blade view name.
 * Override pdfData() to return custom data for that view.
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
     *  - Checks for a matching translation key (export::pdf.users_title)
     *  - Passes headings as column labels and mapped rows as cell values
     */
    public function pdfData(): array
    {
        $records = $this->buildQuery()->get();

        $type     = class_basename(static::class);
        $type     = Str::plural(Str::snake(Str::replaceLast('Export', '', $type)));
        $titleKey = "export::pdf.{$type}_title";
        $title    = Lang::has($titleKey)
            ? __($titleKey)
            : Str::title(str_replace('_', ' ', $type));

        return [
            'title'   => $title,
            'columns' => array_map(fn ($h) => ['label' => $h, 'width' => 'auto'], $this->headings()),
            'rows'    => $records->map(fn ($r) => array_values($this->map($r)))->toArray(),
        ];
    }
}
