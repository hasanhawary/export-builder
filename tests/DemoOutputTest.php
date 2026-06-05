<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Generates real Excel and PDF output files so you can open and inspect them.
 * Files are written to the package root as demo_export.xlsx and demo_export.pdf.
 */
class DemoOutputTest extends TestCase
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
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('demo_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('role')->default('user');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DemoExportUser::insert([
            ['name' => 'Hassan Elhawary', 'email' => 'hassan@example.com', 'role' => 'Admin',  'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ahmed Ali',       'email' => 'ahmed@example.com',  'role' => 'Editor', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sara Mohamed',    'email' => 'sara@example.com',   'role' => 'Viewer', 'is_active' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Omar Youssef',    'email' => 'omar@example.com',   'role' => 'Editor', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Nour Khaled',     'email' => 'nour@example.com',   'role' => 'Viewer', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_generates_excel_file_and_verifies_content(): void
    {
        $response = (new ExportBuilder([
            'page'      => 'demo_export_user',
            'format'    => 'xlsx',
            'timestamp' => 'demo',
        ]))->response();

        // Copy to package root so you can open it
        $dest = dirname(__DIR__) . '/demo_export.xlsx';
        copy($response->getFile()->getRealPath(), $dest);

        // Verify the file is a valid spreadsheet with correct data
        $spreadsheet = IOFactory::load($dest);
        $sheet       = $spreadsheet->getActiveSheet();

        // Headers — is_active must render as translated heading 'Active'
        $this->assertSame('ID',         $sheet->getCell('A1')->getValue());
        $this->assertSame('Name',       $sheet->getCell('B1')->getValue());
        $this->assertSame('Email',      $sheet->getCell('C1')->getValue());
        $this->assertSame('Role',       $sheet->getCell('D1')->getValue());
        $this->assertSame('Active',     $sheet->getCell('E1')->getValue());  // ← translated
        $this->assertSame('Created At', $sheet->getCell('F1')->getValue());  // ← translated

        // Row 1 data — is_active=1 must render as 'Yes'
        $this->assertSame(1,                    $sheet->getCell('A2')->getValue());
        $this->assertSame('Hassan Elhawary',    $sheet->getCell('B2')->getValue());
        $this->assertSame('hassan@example.com', $sheet->getCell('C2')->getValue());
        $this->assertSame('Admin',              $sheet->getCell('D2')->getValue());
        $this->assertSame('Yes',                $sheet->getCell('E2')->getValue()); // ← translated bool

        // Row 3 data — is_active=0 must render as 'No'
        $this->assertSame('No', $sheet->getCell('E4')->getValue()); // Sara (row 4) is inactive

        // 5 data rows
        $this->assertSame(6, $sheet->getHighestRow()); // 1 header + 5 data

        echo "\n✓ Excel: {$dest}\n";
        echo "  Heading E1 : '" . $sheet->getCell('E1')->getValue() . "' (expected: Active)\n";
        echo "  Row 2 E2   : '" . $sheet->getCell('E2')->getValue() . "' (expected: Yes)\n";
        echo "  Row 4 E4   : '" . $sheet->getCell('E4')->getValue() . "' (expected: No)\n";
        echo "  Rows:    " . ($sheet->getHighestRow() - 1) . "\n";
        echo "  Columns: " . $sheet->getHighestColumn() . "\n";
        echo "  Size:    " . number_format(filesize($dest)) . " bytes\n";
    }

    public function test_generates_pdf_file_and_verifies_it_is_valid_pdf(): void
    {
        $response = (new ExportBuilder([
            'page'      => 'demo_export_user',
            'format'    => 'pdf',
            'timestamp' => 'demo',
        ]))->response();

        $src  = $response->getFile()->getRealPath();
        $dest = dirname(__DIR__) . '/demo_export.pdf';
        copy($src, $dest);

        // Verify it's a real PDF
        $header = file_get_contents($dest, false, null, 0, 5);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('%PDF-', $header);
        $this->assertGreaterThan(5000, filesize($dest));

        echo "\n✓ PDF:   {$dest}\n";
        echo "  Size:  " . number_format(filesize($dest)) . " bytes\n";
        echo "  Header: {$header}-...\n";
    }
}

// ---------------------------------------------------------------------------
// Models & Export class
// ---------------------------------------------------------------------------

class DemoExportUser extends Model
{
    protected $table    = 'demo_users';
    protected $fillable = ['name', 'email', 'role', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];
}

class DemoExportUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => DemoExportUser::class,
            'columns' => [
                'id'         => 'int',
                'name'       => 'text',
                'email'      => 'text',
                'role'       => 'text',
                'is_active'  => 'bool',
                'created_at' => 'datetime',
            ],
        ], $filter);
    }
}
