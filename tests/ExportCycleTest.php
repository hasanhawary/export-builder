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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Orchestra\Testbench\TestCase;

class ExportCycleTest extends TestCase
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
        $app['config']->set('export.module.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cycle_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->foreignId('role_id')->nullable();
            $table->timestamps();
        });

        Schema::create('cycle_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cycle_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_user_id');
            $table->string('title');
            $table->timestamps();
        });

        $role = CycleRole::create(['name' => 'Admin']);

        CycleUser::create([
            'name' => 'Hasan',
            'email' => 'hasan@example.test',
            'role_id' => $role->id,
        ]);

        CyclePost::create(['cycle_user_id' => 1, 'title' => 'First Post']);
        CyclePost::create(['cycle_user_id' => 1, 'title' => 'Second Post']);
    }

    public function test_excel_export_cycle_generates_readable_xlsx(): void
    {
        $response = (new ExportBuilder([
            'page' => 'cycle_user',
            'format' => 'xlsx',
            'timestamp' => '20260604_120000',
        ]))->response();

        $this->assertStringContainsString('cycle-user-20260604-120000.xlsx', $response->headers->get('Content-Disposition'));
        $this->assertFileExists($response->getFile()->getRealPath());

        $spreadsheet = IOFactory::load($response->getFile()->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('ID',    $sheet->getCell('A1')->getValue());
        $this->assertSame('Name',  $sheet->getCell('B1')->getValue());
        $this->assertSame('Email', $sheet->getCell('C1')->getValue());
        $this->assertSame('Hasan', $sheet->getCell('B2')->getValue());
        $this->assertSame('hasan@example.test', $sheet->getCell('C2')->getValue());
    }

    public function test_pdf_export_cycle_uses_package_blade_and_generates_pdf(): void
    {
        $export = new CycleUserExport([
            'page' => 'cycle_user',
            'format' => 'pdf',
        ]);

        $this->assertSame('export::pdf.export', $export->pdfView());
        $this->assertSame('Cycle Users', $export->pdfData()['title']);
        $this->assertTrue(view()->exists('export::pdf.export'));

        $response = (new ExportBuilder([
            'page' => 'cycle_user',
            'format' => 'pdf',
            'start' => '2026-06-01',
            'end' => '2026-06-04',
            'timestamp' => '20260604_120000',
        ]))->response();

        $path = $response->getFile()->getRealPath();
        $header = file_get_contents($path, false, null, 0, 5);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('cycle-user-20260604-120000.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-', $header);
        $this->assertGreaterThan(1000, filesize($path));
    }

    public function test_related_export_cycle_generates_expected_relation_columns(): void
    {
        $response = (new ExportBuilder([
            'page' => 'related_cycle_user',
            'format' => 'xlsx',
            'timestamp' => '20260604_120000',
        ]))->response();

        $spreadsheet = IOFactory::load($response->getFile()->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('ID',          $sheet->getCell('A1')->getValue());
        $this->assertSame('Name',        $sheet->getCell('B1')->getValue());
        $this->assertSame('Role',        $sheet->getCell('C1')->getValue());
        $this->assertSame('posts',       $sheet->getCell('D1')->getValue());
        $this->assertSame('posts_total', $sheet->getCell('E1')->getValue());

        $this->assertSame('Hasan', $sheet->getCell('B2')->getValue());
        $this->assertSame('Admin', $sheet->getCell('C2')->getValue());
        $this->assertSame('First Post, Second Post', $sheet->getCell('D2')->getValue());
        $this->assertSame(2, $sheet->getCell('E2')->getValue());
    }
}

class CycleUser extends Model
{
    protected $table = 'cycle_users';

    protected $fillable = ['name', 'email', 'role_id'];

    public function role()
    {
        return $this->belongsTo(CycleRole::class, 'role_id');
    }

    public function posts()
    {
        return $this->hasMany(CyclePost::class, 'cycle_user_id');
    }
}

class CycleRole extends Model
{
    protected $table = 'cycle_roles';

    protected $fillable = ['name'];
}

class CyclePost extends Model
{
    protected $table = 'cycle_posts';

    protected $fillable = ['cycle_user_id', 'title'];
}

class CycleUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => CycleUser::class,
            'columns' => [
                'id' => 'int',
                'name' => 'text',
                'email' => 'text',
            ],
            'relations' => [
                'one' => [],
                'many' => [
                    'concat' => [],
                    'list' => [],
                    'count' => [],
                ],
            ],
        ], $filter);
    }
}

class RelatedCycleUserExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => CycleUser::class,
            'columns' => [
                'id' => 'int',
                'name' => 'text',
            ],
            'relations' => [
                'one' => [
                    'role_id' => ['role' => ['name' => 'text']],
                ],
                'many' => [
                    'concat' => ['posts' => ['title' => 'text']],
                    'list' => [],
                    'count' => ['posts as posts_total'],
                ],
            ],
        ], $filter);
    }
}
