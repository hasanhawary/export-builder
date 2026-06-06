<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Tests for HasPdfOutput::pdfView() and pdfData() — title resolution,
 * column filter consistency, and data shape.
 */
class PdfOutputTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ExcelServiceProvider::class,
            LaravelMpdfServiceProvider::class,
            ExportBuilderServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.module.enabled', false);
        $app['config']->set('export.module.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pdf_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        PdfItem::create(['name' => 'Alpha', 'code' => 'A1']);
        PdfItem::create(['name' => 'Beta',  'code' => 'B2']);
    }

    // =========================================================================
    // pdfView()
    // =========================================================================

    public function test_default_pdf_view_returns_package_blade(): void
    {
        $export = new PdfItemExport([]);

        $this->assertSame('export::pdf.export', $export->pdfView());
    }

    public function test_custom_pdf_view_can_be_overridden(): void
    {
        $export = new PdfItemWithCustomViewExport([]);

        $this->assertSame('custom.view', $export->pdfView());
    }

    // =========================================================================
    // pdfData() — title resolution
    // =========================================================================

    public function test_title_is_derived_from_class_name(): void
    {
        $export = new PdfItemExport([]);
        $data   = $export->pdfData();

        // PdfItemExport → strip 'Export' → 'PdfItem' → snake → 'pdf_item' → plural → 'pdf_items'
        // → title-case → 'Pdf Items'
        $this->assertSame('Pdf Items', $data['title']);
    }

    public function test_title_uses_translation_key_when_available(): void
    {
        // HasPdfOutput checks Lang::has("export::export.{$type}_title")
        // Register under 'export' namespace, 'export' file
        app('translator')->addLines(['export.pdf_items_title' => 'Custom Items Title'], 'en', 'export');

        $export = new PdfItemExport([]);
        $data   = $export->pdfData();

        $this->assertSame('Custom Items Title', $data['title']);
    }

    public function test_title_fallback_for_multi_word_class_name(): void
    {
        // PdfTicketCategoryExport → strip 'Export' → 'PdfTicketCategory'
        // → snake → 'pdf_ticket_category' → plural → 'pdf_ticket_categories'
        // → title-case → 'Pdf Ticket Categories'
        $export = new PdfTicketCategoryExport([]);
        $data   = $export->pdfData();

        $this->assertSame('Pdf Ticket Categories', $data['title']);
    }

    // =========================================================================
    // pdfData() — columns shape
    // =========================================================================

    public function test_pdf_data_columns_have_label_and_width_keys(): void
    {
        $export  = new PdfItemExport([]);
        $data    = $export->pdfData();
        $columns = $data['columns'];

        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertArrayHasKey('label', $column);
            $this->assertArrayHasKey('width', $column);
            $this->assertSame('auto', $column['width']);
        }
    }

    public function test_pdf_data_column_count_matches_heading_count(): void
    {
        $export = new PdfItemExport([]);

        $this->assertCount(
            count($export->headings()),
            $export->pdfData()['columns']
        );
    }

    // =========================================================================
    // pdfData() — rows shape
    // =========================================================================

    public function test_pdf_data_rows_count_matches_record_count(): void
    {
        $export = new PdfItemExport([]);
        $data   = $export->pdfData();

        $this->assertCount(2, $data['rows']);
    }

    public function test_pdf_data_rows_are_indexed_arrays(): void
    {
        $export = new PdfItemExport([]);
        $data   = $export->pdfData();

        foreach ($data['rows'] as $row) {
            $this->assertIsArray($row);
            // Rows must be sequential (array_values) not associative
            $this->assertSame(array_values($row), $row);
        }
    }

    // =========================================================================
    // pdfData() + column filter — Issue 3 regression
    // =========================================================================

    public function test_pdf_data_honors_column_filter(): void
    {
        // Only request 'name' — 'code' must not appear in rows
        $export  = new PdfItemExport(['columns' => ['name']]);
        $data    = $export->pdfData();
        $columns = $data['columns'];

        $this->assertCount(1, $columns);
        // 'name' column translates to 'Name' via the package lang file
        $this->assertSame('Name', $columns[0]['label']);

        // Each row should have exactly one cell
        foreach ($data['rows'] as $row) {
            $this->assertCount(1, $row);
        }
    }

    public function test_pdf_data_column_filter_does_not_mutate_subsequent_heading_call(): void
    {
        $export = new PdfItemExport(['columns' => ['name']]);

        // Call pdfData first (which calls headings internally)
        $export->pdfData();

        // headings() returns translated labels — 'name' → 'Name', 'code' has no translation
        $headings = $export->headings();
        $this->assertContains('Name', $headings);
        $this->assertNotContains('code', $headings);
        $this->assertNotContains('Code', $headings);
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

class PdfItem extends Model
{
    protected $table    = 'pdf_items';
    protected $fillable = ['name', 'code'];
}

class PdfItemExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => PdfItem::class,
            'columns' => ['name' => 'text', 'code' => 'text'],
        ], $filter);
    }
}

class PdfItemWithCustomViewExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => PdfItem::class,
            'columns' => ['name' => 'text'],
        ], $filter);
    }

    public function pdfView(): string
    {
        return 'custom.view';
    }
}

// Used to test multi-word class name title derivation
class PdfTicketCategoryExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => PdfItem::class,
            'columns' => ['name' => 'text'],
        ], $filter);
    }
}
