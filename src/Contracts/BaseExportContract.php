<?php

namespace HasanHawary\ExportBuilder\Contracts;

/**
 * Contract for all export classes in the Export Builder package.
 *
 * Host applications can type-hint against this interface instead of
 * the concrete BaseExport class, enabling custom implementations that
 * don't extend BaseExport.
 *
 * ExportBuilder resolves the export class dynamically — any class that
 * implements this contract will work with the package.
 */
interface BaseExportContract
{
    /**
     * Return false to disable this export entirely.
     * ExportBuilder will abort with 403 when this returns false.
     */
    public function isEnabled(): bool;

    /**
     * The Blade view used to render the PDF output.
     */
    public function pdfView(): string;

    /**
     * Data array passed to the PDF Blade view.
     *
     * @return array{title: string, columns: list<array{label: string, width: string}>, rows: list<list<mixed>>}
     */
    public function pdfData(): array;
}
