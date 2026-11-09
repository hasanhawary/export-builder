<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Models\ExportFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ExportableRelationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('exportable_cars', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('export_files', function (Blueprint $table): void {
            $table->id();
            $table->string('exportable_type');
            $table->unsignedBigInteger('exportable_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('disk')->nullable();
            $table->string('format')->default('xlsx');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    private function file(string $type, ?int $id = null): ExportFile
    {
        return ExportFile::create(['exportable_type' => $type, 'exportable_id' => $id, 'format' => 'xlsx', 'status' => 'completed']);
    }

    public function test_page_slug_resolves_to_the_model_of_its_export_class(): void
    {
        $this->assertSame(ExportableCar::class, ExportFile::resolveExportableClass('exportable_car'));
    }

    public function test_page_export_without_an_id_has_no_exportable(): void
    {
        $this->assertNull($this->file('exportable_car')->exportable);
    }

    public function test_page_export_with_an_id_returns_the_record(): void
    {
        $car = ExportableCar::create(['name' => 'Civic']);

        $this->assertTrue($car->is($this->file('exportable_car', $car->id)->exportable));
    }

    public function test_morph_map_alias_resolves(): void
    {
        Relation::morphMap(['vehicle' => ExportableCar::class]);
        $car = ExportableCar::create(['name' => 'Corolla']);

        $this->assertTrue($car->is($this->file('vehicle', $car->id)->exportable));
    }

    public function test_class_name_type_from_associate_resolves(): void
    {
        $car = ExportableCar::create(['name' => 'Accord']);
        $file = $this->file('exportable_car');

        $file->exportable()->associate($car)->save();

        $this->assertSame(ExportableCar::class, $file->fresh()->exportable_type);
        $this->assertTrue($car->is($file->fresh()->exportable));
    }

    public function test_unknown_type_returns_null_instead_of_class_not_found(): void
    {
        $this->assertNull(ExportFile::resolveExportableClass('page_that_does_not_exist'));
        $this->assertNull($this->file('page_that_does_not_exist', 5)->exportable);
    }

    public function test_eager_loading_mixed_types_works(): void
    {
        $car = ExportableCar::create(['name' => 'Golf']);
        $this->file('exportable_car', $car->id);
        $this->file('page_that_does_not_exist', 5);
        $this->file('exportable_car');

        $files = ExportFile::with('exportable')->orderBy('id')->get();

        $this->assertTrue($car->is($files[0]->exportable));
        $this->assertNull($files[1]->exportable);
        $this->assertNull($files[2]->exportable);
    }

    public function test_a_page_with_a_non_export_class_is_not_resolved(): void
    {
        $this->assertNull(ExportFile::resolveExportableClass('not_a_real'));
    }
}

class ExportableCar extends Model
{
    protected $table = 'exportable_cars';

    protected $guarded = [];

    public $timestamps = false;
}

class ExportableCarExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct(['model' => ExportableCar::class, 'columns' => ['id' => 'int', 'name' => 'text']], $filter);
    }
}

/** In the export namespace and ending in "Export", but not an export. */
class NotARealExport
{
}
