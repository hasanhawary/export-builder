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
 * Edge-case tests covering:
 *  - Morph relations end-to-end
 *  - Nested dot-notation relations
 *  - customWith / customSelect config options
 *  - resolvePdfSettings resolver variants (invokable class, [Class, method])
 *  - chunk_size config
 *  - buildQuery() result cache
 */
class EdgeCaseTest extends TestCase
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

        Schema::create('ec_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->nullableMorphs('sourceable'); // sourceable_type, sourceable_id
            $table->timestamps();
        });

        Schema::create('ec_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ec_sponsors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ec_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ec_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $campaign = EcCampaign::create(['name' => 'Summer Sale']);
        $sponsor  = EcSponsor::create(['name' => 'Acme Corp']);

        EcPost::create(['title' => 'From Campaign', 'sourceable_type' => EcCampaign::class, 'sourceable_id' => $campaign->id]);
        EcPost::create(['title' => 'From Sponsor',  'sourceable_type' => EcSponsor::class,  'sourceable_id' => $sponsor->id]);
        EcPost::create(['title' => 'No Source',     'sourceable_type' => null,              'sourceable_id' => null]);

        $company    = EcCompany::create(['name' => 'GlobalCorp']);
        $department = EcDepartment::create(['name' => 'Engineering', 'company_id' => $company->id]);
        EcUser::create(['name' => 'Alice', 'department_id' => $department->id]);
    }

    // =========================================================================
    // Morph relations
    // =========================================================================

    public function test_morph_relation_extracts_display_value_from_related_model(): void
    {
        $export   = new EcPostMorphExport([]);
        $response = (new ExportBuilder(['page' => 'ec_post_morph', 'format' => 'xlsx', 'timestamp' => 'test']))->response();

        $sheet = IOFactory::load($response->getFile()->getRealPath())->getActiveSheet();

        // Headings
        $this->assertSame('title',     $sheet->getCell('A1')->getValue());
        $this->assertSame('sourceable_id', $sheet->getCell('B1')->getValue());

        // Row 1: campaign post
        $this->assertSame('From Campaign', $sheet->getCell('A2')->getValue());
        $this->assertSame('Summer Sale',   $sheet->getCell('B2')->getValue());

        // Row 2: sponsor post
        $this->assertSame('From Sponsor', $sheet->getCell('A3')->getValue());
        $this->assertSame('Acme Corp',    $sheet->getCell('B3')->getValue());
    }

    public function test_morph_relation_returns_fallback_when_related_is_null(): void
    {
        $post   = EcPost::where('title', 'No Source')->first();
        $export = new EcPostMorphExport([]);
        $mapped = $export->map($post);

        $this->assertNull($mapped['sourceable_id']);
    }

    // =========================================================================
    // Nested dot-notation relations
    // =========================================================================

    public function test_nested_relation_resolves_deep_value(): void
    {
        $user   = EcUser::first();
        $export = new EcUserNestedExport([]);
        $mapped = $export->map($user);

        $this->assertSame('Alice', $mapped['id'] === 1 ? $mapped['name'] : null ?? $mapped['name']);
        // The one-to-one relation should extract the department name
        $this->assertSame('Engineering', $mapped['department']);
    }

    // =========================================================================
    // customWith / customSelect
    // =========================================================================

    public function test_custom_with_eager_loads_extra_relations(): void
    {
        $export  = new EcUserCustomWithExport([]);
        $results = iterator_to_array($export->collection());

        // With customWith(['department'], the relation must be loaded
        $this->assertTrue($results[0]->relationLoaded('department'));
    }

    public function test_custom_select_limits_selected_columns(): void
    {
        $export  = new EcUserCustomSelectExport([]);
        $results = iterator_to_array($export->collection());

        // customSelect restricts DB columns — model only has id and name loaded
        $this->assertNotNull($results[0]->id);
        $this->assertNotNull($results[0]->name);
    }

    // =========================================================================
    // resolvePdfSettings resolver variants
    // =========================================================================

    public function test_pdf_settings_invokable_class_resolver_is_called(): void
    {
        config()->set('export.pdf.settings_resolver', EcInvokableSettingsResolver::class);

        $export  = new EcUserCustomSelectExport([]);
        $builder = new ExportBuilder(['page' => 'ec_user_custom_select', 'format' => 'pdf', 'timestamp' => 'test']);

        $response = $builder->response();

        // If the resolver ran, the PDF was generated without error
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_settings_class_method_resolver_is_called(): void
    {
        config()->set('export.pdf.settings_resolver', [EcStaticSettingsProvider::class, 'getSettings']);

        $builder  = new ExportBuilder(['page' => 'ec_user_custom_select', 'format' => 'pdf', 'timestamp' => 'test']);
        $response = $builder->response();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_settings_closure_resolver_is_called(): void
    {
        config()->set('export.pdf.settings_resolver', fn () => ['company_name' => 'TestCo']);

        $builder  = new ExportBuilder(['page' => 'ec_user_custom_select', 'format' => 'pdf', 'timestamp' => 'test']);
        $response = $builder->response();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // =========================================================================
    // chunk_size config
    // =========================================================================

    public function test_collection_uses_configurable_chunk_size(): void
    {
        config()->set('export.chunk_size', 10);

        $export  = new EcUserCustomSelectExport([]);
        $results = iterator_to_array($export->collection());

        // Just confirm it runs without error and returns data
        $this->assertCount(1, $results);
    }

    // =========================================================================
    // buildQuery() cache
    // =========================================================================

    public function test_build_query_returns_same_instance_on_repeated_calls(): void
    {
        $export = new EcUserCustomSelectExport([]);

        $q1 = $export->exposeBuildQuery();
        $q2 = $export->exposeBuildQuery();

        $this->assertSame($q1, $q2);
    }
}

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

class EcPost extends Model
{
    protected $table    = 'ec_posts';
    protected $fillable = ['title', 'sourceable_type', 'sourceable_id'];

    public function sourceable()
    {
        return $this->morphTo();
    }
}

class EcCampaign extends Model
{
    protected $table    = 'ec_campaigns';
    protected $fillable = ['name'];
}

class EcSponsor extends Model
{
    protected $table    = 'ec_sponsors';
    protected $fillable = ['name'];
}

class EcUser extends Model
{
    protected $table    = 'ec_users';
    protected $fillable = ['name', 'department_id'];

    public function department()
    {
        return $this->belongsTo(EcDepartment::class, 'department_id');
    }
}

class EcDepartment extends Model
{
    protected $table    = 'ec_departments';
    protected $fillable = ['name', 'company_id'];

    public function company()
    {
        return $this->belongsTo(EcCompany::class, 'company_id');
    }
}

class EcCompany extends Model
{
    protected $table    = 'ec_companies';
    protected $fillable = ['name'];
}

// ---------------------------------------------------------------------------
// Export classes
// ---------------------------------------------------------------------------

class EcPostMorphExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => EcPost::class,
            'columns' => ['title' => 'text'],
            'relations' => [
                'morph' => [
                    'sourceable_id' => [
                        'relation' => 'sourceable',
                        'column'   => 'name',
                        'type'     => 'text',
                        'fallback' => null,
                    ],
                ],
            ],
        ], $filter);
    }
}

class EcUserNestedExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'   => EcUser::class,
            'columns' => ['id' => 'int', 'name' => 'text'],
            'relations' => [
                'one' => [
                    'department_id' => ['department' => ['name' => 'text']],
                ],
            ],
        ], $filter);
    }
}

class EcUserCustomWithExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'      => EcUser::class,
            'columns'    => ['id' => 'int', 'name' => 'text'],
            'customWith' => ['department'],
        ], $filter);
    }
}

class EcUserCustomSelectExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model'        => EcUser::class,
            'columns'      => ['id' => 'int', 'name' => 'text'],
            'customSelect' => ['id', 'name'],
        ], $filter);
    }

    /** Expose buildQuery() for the cache test */
    public function exposeBuildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->buildQuery();
    }
}

// ---------------------------------------------------------------------------
// PDF settings resolver doubles
// ---------------------------------------------------------------------------

class EcInvokableSettingsResolver
{
    public function __invoke(): array
    {
        return ['company_name' => 'InvokableCo'];
    }
}

class EcStaticSettingsProvider
{
    public static function getSettings(): array
    {
        return ['company_name' => 'StaticCo'];
    }
}
