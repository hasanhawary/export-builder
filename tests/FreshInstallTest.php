<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A brand-new project: nothing published (no config, lang, view or migration),
 * only one export class. Everything must work out of the box.
 */
class FreshInstallTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExcelServiceProvider::class, LaravelMpdfServiceProvider::class, ExportBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The only thing a project provides: where its export classes live.
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileDoesNotExist(config_path('export.php'), 'test needs an unpublished config');
        $this->assertFileDoesNotExist(lang_path('ar/export.php'), 'test needs unpublished translations');

        // The package's own migration, run the normal way.
        $this->artisan('migrate')->assertSuccessful();

        Schema::create('fresh_items', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        FreshItem::create(['name' => 'Alpha']);
        FreshItem::create(['name' => 'ألفا', 'is_active' => false]);
    }

    public function test_the_whole_flow_works_with_zero_setup(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = new FreshUser(7);

        // Routes exist and require login by default.
        $this->getJson('/api/export-log')->assertUnauthorized();

        // Direct download, in Arabic, with Arabic package strings (no published lang files).
        $response = $this->actingAs($user)->get('/api/export-direct?page=fresh_item&lang=ar');
        $response->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getRealPath())->getActiveSheet();
        $this->assertSame('الاسم', $sheet->getCell('B1')->getValue());
        $this->assertSame('نعم', $sheet->getCell('C2')->getValue());
        $this->assertTrue($sheet->getRightToLeft());

        // PDF works too.
        $this->actingAs($user)->get('/api/export-direct?page=fresh_item&format=pdf&lang=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Queued export → stored on the app's default disk → listed → downloaded.
        $this->actingAs($user)->postJson('/api/export', ['page' => 'fresh_item', 'format' => 'csv', 'lang' => 'en'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Export started successfully.');

        $list = $this->actingAs($user)->getJson('/api/export-log')->assertOk();
        $list->assertJsonPath('data.0.status', 'completed')->assertJsonPath('meta.total', 1);

        $id = $list->json('data.0.id');
        $download = $this->actingAs($user)->get("/api/export-log/{$id}/download")->assertOk();
        $this->assertStringContainsString('Alpha', $download->streamedContent());

        // Another user sees nothing of it.
        $this->actingAs(new FreshUser(8))->getJson('/api/export-log')->assertOk()->assertJsonPath('meta.total', 0);

        // Trash and restore.
        $this->actingAs($user)->deleteJson("/api/export-log/{$id}")->assertOk();
        $this->actingAs($user)->getJson('/api/export-log?trashed=only')->assertJsonPath('meta.total', 1);
        $this->actingAs($user)->postJson('/api/export-log/restore', ['ids' => [$id]])->assertOk();
    }
}

class FreshItem extends Model
{
    protected $table = 'fresh_items';

    protected $guarded = [];
}

class FreshItemExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct(['model' => FreshItem::class, 'columns' => ['id' => 'int', 'name' => 'text', 'is_active' => 'bool']], $filter);
    }
}

class FreshUser extends Authenticatable
{
    public function __construct(private int $userId = 1)
    {
        parent::__construct();
        $this->setAttribute('id', $userId);
    }

    public function getAuthIdentifier(): int
    {
        return $this->userId;
    }
}
