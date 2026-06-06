<?php

namespace HasanHawary\ExportBuilder\Concerns;

use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Applies default spreadsheet styles to the exported worksheet:
 *  - Bold header row
 *  - Thin borders on all cells
 *
 * Override styles() in your export class to customise the appearance.
 */
trait HasExcelStyles
{
    public function styles(Worksheet $sheet): void
    {
        $highestRow    = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        if ($highestRow < 1) {
            return;
        }

        // Bold header row
        $sheet->getStyle("A1:{$highestColumn}1")
            ->getFont()
            ->setBold(true);

        // Thin border on every cell
        $sheet->getStyle("A1:{$highestColumn}{$highestRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
    }
}
