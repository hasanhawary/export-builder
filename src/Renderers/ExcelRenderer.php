<?php

namespace HasanHawary\ExportBuilder\Renderers;

use HasanHawary\ExportBuilder\ExportBuilder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders a BaseExport instance to an Excel/CSV binary file response.
 *
 * Owned by ExportBuilder — extracted here to keep ExportBuilder focused
 * on class resolution and dispatch, not on rendering mechanics.
 */
final class ExcelRenderer
{
    public function __construct(private readonly array $filter) {}

    /**
     * Render the export object to a downloadable Excel/CSV response.
     */
    public function render(object $exportObject, string $format): BinaryFileResponse
    {
        $excelFormat = match ($format) {
            'csv'   => \Maatwebsite\Excel\Excel::CSV,
            'xls'   => \Maatwebsite\Excel\Excel::XLS,
            default => \Maatwebsite\Excel\Excel::XLSX,
        };

        $fileName = ExportBuilder::buildFileName($this->filter, $format);

        return Excel::download($exportObject, $fileName, $excelFormat);
    }
}
