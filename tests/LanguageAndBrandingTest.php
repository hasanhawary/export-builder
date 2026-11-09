<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\Facades\LaravelMpdf;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The file language (request 'lang' / app locale), its validation, RTL layout,
 * single-language PDFs, per-project branding and the export:translations command.
 */
class LanguageAndBrandingTest extends TestCase
{
    /** English words of the package's own PDF strings, which must never appear in an Arabic file. */
    private const PACKAGE_EN = ['Total Records', 'Generated at', 'Date Range', 'Page ', 'Export Report', 'Yes', 'No data'];

    /** Arabic words of the package's own PDF strings, which must never appear in an English file. */
    private const PACKAGE_AR = ['إجمالي السجلات', 'تم الإنشاء', 'نطاق التاريخ', 'صفحة', 'تقرير التصدير', 'نعم'];

    protected function getPackageProviders($app): array
    {
        return [ExcelServiceProvider::class, LaravelMpdfServiceProvider::class, ExportBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.routes.middleware', []);
        $app['config']->set('app.locale', 'en');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lb_people', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
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

        LbPerson::create(['name' => 'Hasan', 'is_active' => true]);
        LbPerson::create(['name' => 'سارة', 'is_active' => false]);

        app()->setLocale('en');
    }

    private function xlsx(array $filters): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $response = (new ExportBuilder(['page' => 'lb_person', 'format' => 'xlsx', ...$filters]))->response();

        return IOFactory::load($response->getFile()->getRealPath())->getActiveSheet();
    }

    /** Render the PDF and capture the HTML + mPDF options that would be converted. */
    private array $capturedPdf = [];

    private bool $pdfMocked = false;

    private function pdf(array $filters, string $page = 'lb_person'): array
    {
        if (! $this->pdfMocked) {
            $pdf = Mockery::mock();
            $pdf->shouldReceive('output')->andReturn('%PDF-fake');

            LaravelMpdf::shouldReceive('loadHTML')->andReturnUsing(function ($html, $config) use ($pdf) {
                $this->capturedPdf = ['html' => $html, 'config' => $config];

                return $pdf;
            });

            $this->pdfMocked = true;
        }

        $this->capturedPdf = [];
        (new ExportBuilder(['page' => $page, 'format' => 'pdf', ...$filters]))->response();

        return $this->capturedPdf;
    }

    // ---------------------------------------------------------------------
    // Locale normalisation
    // ---------------------------------------------------------------------

    public function test_lang_values_are_normalised_or_rejected(): void
    {
        $cases = [
            'ar' => 'ar', 'AR' => 'ar', ' ar ' => 'ar', 'ar_EG' => 'ar', 'ar-eg' => 'ar', 'AR-SA' => 'ar',
            'en' => 'en', 'en-US' => 'en', 'EN_gb' => 'en',
            '' => null, '   ' => null, 'xx' => null, 'fr' => null, 'arabic' => null, 'a' => null,
            'ar-' => null, '-ar' => null, '../../etc/passwd' => null, 'ar;drop' => null,
            str_repeat('a', 300) => null, 'ar-EG-x-private-very-long-tag' => 'ar',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, ExportBuilder::normalizeLocale($input), "lang=" . var_export($input, true));
        }

        foreach ([null, 1, 1.5, true, ['ar'], (object) ['ar']] as $input) {
            $this->assertNull(ExportBuilder::normalizeLocale($input));
        }
    }

    public function test_supported_locales_come_from_config(): void
    {
        config()->set('export.locales', ['EN', 'fr_CA', '', null]);

        $this->assertSame(['en', 'fr-ca'], ExportBuilder::supportedLocales());
        $this->assertSame('fr-ca', ExportBuilder::normalizeLocale('fr_CA'));
        $this->assertNull(ExportBuilder::normalizeLocale('ar'));
    }

    public function test_rtl_detection(): void
    {
        foreach (['ar' => true, 'ar-EG' => true, 'AR_sa' => true, 'he' => true, 'en' => false, 'fr' => false] as $locale => $rtl) {
            $this->assertSame($rtl, ExportBuilder::isRtl($locale), $locale);
        }
    }

    // ---------------------------------------------------------------------
    // Request validation
    // ---------------------------------------------------------------------

    public function test_invalid_lang_in_the_request_is_422(): void
    {
        $user = new LbUser(1);

        foreach (['xx', 'fr', '../../x', 'arabic'] as $lang) {
            $this->actingAs($user)->getJson('/api/export-direct?page=lb_person&lang=' . urlencode($lang))
                ->assertStatus(422)->assertJsonValidationErrors('lang');
        }

        $this->actingAs($user)->getJson('/api/export-direct?page=lb_person&lang[]=ar')
            ->assertStatus(422)->assertJsonValidationErrors('lang');

        $this->actingAs($user)->postJson('/api/export', ['page' => 'lb_person', 'lang' => 'zz'])
            ->assertStatus(422)->assertJsonValidationErrors('lang');
    }

    public function test_valid_lang_variants_in_the_request_are_accepted(): void
    {
        $user = new LbUser(1);

        foreach (['ar', 'AR', 'ar_EG', 'ar-sa', 'en', 'EN-us', ''] as $lang) {
            $this->actingAs($user)->get('/api/export-direct?page=lb_person&format=csv&lang=' . urlencode($lang))
                ->assertOk();
        }
    }

    // ---------------------------------------------------------------------
    // Excel in the requested language
    // ---------------------------------------------------------------------

    public function test_excel_is_fully_in_arabic_and_right_to_left(): void
    {
        $sheet = $this->xlsx(['lang' => 'ar']);

        $this->assertSame('المعرف', $sheet->getCell('A1')->getValue());
        $this->assertSame('الاسم', $sheet->getCell('B1')->getValue());
        $this->assertSame('نعم', $sheet->getCell('C2')->getValue());
        $this->assertSame('لا', $sheet->getCell('C3')->getValue());
        $this->assertTrue($sheet->getRightToLeft());
    }

    public function test_excel_is_fully_in_english_and_left_to_right(): void
    {
        app()->setLocale('ar');

        $sheet = $this->xlsx(['lang' => 'en']);

        $this->assertSame('ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Yes', $sheet->getCell('C2')->getValue());
        $this->assertFalse($sheet->getRightToLeft());
    }

    public function test_without_lang_the_app_locale_is_used(): void
    {
        app()->setLocale('ar');
        $this->assertSame('الاسم', $this->xlsx([])->getCell('B1')->getValue());

        app()->setLocale('en');
        $this->assertSame('Name', $this->xlsx([])->getCell('B1')->getValue());
    }

    public function test_an_unsupported_lang_passed_directly_falls_back_to_the_app_locale(): void
    {
        app()->setLocale('en');

        $this->assertSame('Name', $this->xlsx(['lang' => 'xx'])->getCell('B1')->getValue());
    }

    public function test_app_and_carbon_locale_are_restored_after_the_export(): void
    {
        app()->setLocale('en');
        Carbon::setLocale('en');

        $this->xlsx(['lang' => 'ar']);

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', Carbon::getLocale());
    }

    public function test_locale_is_restored_even_when_the_export_fails(): void
    {
        app()->setLocale('en');

        try {
            (new ExportBuilder(['page' => 'does_not_exist', 'lang' => 'ar']))->response();
            $this->fail('Expected a 404.');
        } catch (HttpException) {
        }

        $this->assertSame('en', app()->getLocale());
    }

    // ---------------------------------------------------------------------
    // Queued exports keep the requester's language
    // ---------------------------------------------------------------------

    public function test_queued_export_is_built_in_the_requested_language(): void
    {
        Queue::fake();
        app()->setLocale('en');

        $this->actingAs(new LbUser(1))->postJson('/api/export', ['page' => 'lb_person', 'lang' => 'AR_eg'])->assertStatus(202);

        $export = ExportFile::query()->latest('id')->firstOrFail();
        $this->assertSame('ar', $export->metadata['filters']['lang']);

        // The worker runs with its own (English) locale: the file is still Arabic.
        Storage::fake('local');
        $export->update(['disk' => 'local']);
        app()->setLocale('en');
        (new ExportToFile($export->id))->handle(app(ExportFileService::class));

        $path  = Storage::disk('local')->path($export->refresh()->file_path);
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame('الاسم', $sheet->getCell('B1')->getValue());
        $this->assertSame('en', app()->getLocale());
    }

    public function test_queued_export_without_lang_keeps_the_request_locale(): void
    {
        Queue::fake();
        app()->setLocale('ar');

        $this->actingAs(new LbUser(1))->postJson('/api/export', ['page' => 'lb_person'])->assertStatus(202);

        $this->assertSame('ar', ExportFile::query()->latest('id')->firstOrFail()->metadata['filters']['lang']);
    }

    public function test_queued_job_runs_as_the_requester(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
        config()->set('auth.providers.users.model', LbAuthUser::class);
        $requester = LbAuthUser::create(['name' => 'Requester']);

        Storage::fake('local');
        $export = ExportFile::create([
            'exportable_type' => 'lb_whoami', 'format' => 'csv', 'status' => 'pending', 'disk' => 'local',
            'created_by' => $requester->id, 'metadata' => ['filters' => ['page' => 'lb_whoami', 'format' => 'csv']],
        ]);

        (new ExportToFile($export->id))->handle(app(ExportFileService::class));

        $this->assertSame([$requester->id], LbWhoamiExport::$seenUsers);
        $this->assertFalse(auth()->check(), 'the requester is logged out again after the job');

        config()->set('export.module.queue.run_as_requester', false);
        LbWhoamiExport::$seenUsers = [];
        (new ExportToFile($export->id))->handle(app(ExportFileService::class));
        $this->assertSame([null], LbWhoamiExport::$seenUsers);
    }

    // ---------------------------------------------------------------------
    // PDF: one language per file
    // ---------------------------------------------------------------------

    public function test_arabic_pdf_has_no_english_package_text_and_is_rtl(): void
    {
        config()->set('export.pdf.brand.name', ['ar' => 'شركة المياه', 'en' => 'Water Co']);

        $pdf = $this->pdf(['lang' => 'ar', 'start' => '2026-01-01', 'end' => '2099-12-31']);

        $this->assertSame('rtl', $pdf['config']['directionality']);
        $this->assertStringContainsString('dir="rtl"', $pdf['html']);
        $this->assertStringContainsString('شركة المياه', $pdf['html']);
        $this->assertStringNotContainsString('Water Co', $pdf['html']);
        $this->assertStringContainsString('تقرير التصدير', $pdf['html']);     // title: no translation → generic Arabic
        $this->assertStringContainsString('<bdo dir="ltr">2026-01-01</bdo>', $pdf['html']);
        $this->assertStringContainsString('نعم', $pdf['html']);
        $this->assertStringNotContainsString('export::', $pdf['html']);

        foreach (self::PACKAGE_EN as $english) {
            $this->assertStringNotContainsString($english, strip_tags($pdf['html']), "English text in an Arabic PDF: {$english}");
        }
    }

    public function test_english_pdf_has_no_arabic_package_text_and_is_ltr(): void
    {
        config()->set('export.pdf.brand.name', ['ar' => 'شركة المياه', 'en' => 'Water Co']);
        app()->setLocale('ar');

        $pdf = $this->pdf(['lang' => 'en']);

        $this->assertSame('ltr', $pdf['config']['directionality']);
        $this->assertStringContainsString('Water Co', $pdf['html']);
        $this->assertStringContainsString('Lb People', $pdf['html']);         // English title from the class
        $this->assertStringNotContainsString('export::', $pdf['html']);

        foreach (self::PACKAGE_AR as $arabic) {
            $this->assertStringNotContainsString($arabic, $pdf['html'], "Arabic text in an English PDF: {$arabic}");
        }
    }

    public function test_brand_name_missing_for_the_file_language_is_not_shown_in_another_language(): void
    {
        config()->set('export.pdf.brand.name', ['en' => 'Only English Co']);

        $this->assertStringNotContainsString('Only English Co', $this->pdf(['lang' => 'ar'])['html']);
        $this->assertStringContainsString('Only English Co', $this->pdf(['lang' => 'en'])['html']);
    }

    public function test_pdf_title_prefers_the_users_filename_then_translations(): void
    {
        $this->assertStringContainsString('تقرير الربع الأول', $this->pdf(['lang' => 'ar', 'filename' => 'تقرير الربع الأول'])['html']);

        foreach (['ar', 'en'] as $l) {
            __('export::export.yes', [], $l);
        }
        app('translator')->addLines(['export.lb_people_title' => 'تقرير الأشخاص'], 'ar', 'export');

        $this->assertStringContainsString('تقرير الأشخاص', $this->pdf(['lang' => 'ar'])['html']);
    }

    public function test_pdf_title_also_reads_the_pages_convention(): void
    {
        foreach (['ar', 'en'] as $l) {
            __('export::export.yes', [], $l);
        }
        app('translator')->addLines(['export.pages.lb_person' => 'الأشخاص'], 'ar', 'export');

        $this->assertStringContainsString('<title>الأشخاص</title>', $this->pdf(['lang' => 'ar'])['html']);

        $this->artisan('export:translations', ['pages' => ['lb_person'], '--locale' => ['ar']])
            ->expectsOutputToContain('Every heading is translated')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------------
    // Branding per project
    // ---------------------------------------------------------------------

    public function test_brand_color_font_and_logo_from_config(): void
    {
        @mkdir(public_path('brand'), 0777, true);
        file_put_contents(public_path('brand/logo.png'), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        config()->set('export.pdf.brand', [
            'name' => 'Acme', 'logo' => 'brand/logo.png', 'primary_color' => '#AA3300', 'font' => 'XBRiyaz',
        ]);

        $pdf = $this->pdf(['lang' => 'en']);

        $this->assertStringContainsString('#AA3300', $pdf['html']);
        $this->assertStringContainsString(public_path('brand/logo.png'), $pdf['html']);
        $this->assertStringContainsString('font-family: xbriyaz', $pdf['html']);
        $this->assertSame('xbriyaz', $pdf['config']['default_font']);

        @unlink(public_path('brand/logo.png'));
        @rmdir(public_path('brand'));
    }

    public function test_unsafe_brand_values_fall_back_to_safe_defaults(): void
    {
        config()->set('export.pdf.brand', [
            'name' => '<script>alert(1)</script>',
            'logo' => 'javascript:alert(1)',
            'primary_color' => 'red; background: url(x)',
            'font' => 'arial; } body { display:none',
        ]);

        $pdf = $this->pdf(['lang' => 'en']);

        $this->assertStringNotContainsString('<script>', $pdf['html']);
        $this->assertStringContainsString('&lt;script&gt;', $pdf['html']);
        $this->assertStringNotContainsString('javascript:', $pdf['html']);
        $this->assertStringNotContainsString('url(x)', $pdf['html']);
        $this->assertStringContainsString('#1e3a5f', $pdf['html']);
        $this->assertSame('dejavusanscondensed', $pdf['config']['default_font']);
    }

    public function test_settings_and_resolver_override_the_brand_per_tenant(): void
    {
        config()->set('export.pdf.brand', ['name' => 'Config Co', 'primary_color' => '#111111']);
        config()->set('export.pdf.settings', ['company_name' => 'Legacy Co']);
        $this->assertStringContainsString('Legacy Co', $this->pdf(['lang' => 'en'])['html']);

        config()->set('export.pdf.settings_resolver', fn () => ['company_name' => ['ar' => 'المستأجر', 'en' => 'Tenant Co'], 'primary_color' => '#222222']);
        $ar = $this->pdf(['lang' => 'ar'])['html'];
        $this->assertStringContainsString('المستأجر', $ar);
        $this->assertStringContainsString('#222222', $ar);
        $this->assertStringNotContainsString('Config Co', $ar);
    }

    public function test_orientation_follows_the_column_count_or_config(): void
    {
        $this->assertSame('A4', $this->pdf(['lang' => 'en'])['config']['format']);
        $this->assertSame('A4-L', $this->pdf(['lang' => 'en'], 'lb_wide')['config']['format']);

        config()->set('export.pdf.orientation', 'portrait');
        $this->assertSame('A4', $this->pdf(['lang' => 'en'], 'lb_wide')['config']['format']);

        config()->set('export.pdf.orientation', 'landscape');
        $this->assertSame('A4-L', $this->pdf(['lang' => 'en'])['config']['format']);
    }

    // ---------------------------------------------------------------------
    // export:translations
    // ---------------------------------------------------------------------

    public function test_translations_command_lists_missing_headings_and_titles(): void
    {
        $this->artisan('export:translations', ['pages' => ['lb_custom'], '--locale' => ['ar', 'en']])
            ->expectsOutputToContain('lb_customs (title)')   // ar row: heading + title missing
            ->expectsOutputToContain('salary')               // en row: heading missing
            ->assertFailed();

        foreach (['ar', 'en'] as $l) {
            __('export::export.yes', [], $l);
        }
        app('translator')->addLines(['export.salary' => 'الراتب', 'export.lb_customs' => 'تقرير'], 'ar', 'export');
        app('translator')->addLines(['export.salary' => 'Salary'], 'en', 'export');

        $this->artisan('export:translations', ['pages' => ['lb_custom'], '--locale' => ['ar', 'en']])
            ->expectsOutputToContain('Every heading is translated')
            ->assertSuccessful();

        $this->artisan('export:translations', ['pages' => ['not_a_page']])->assertFailed();
    }
}

class LbPerson extends Model
{
    protected $table = 'lb_people';

    protected $guarded = [];
}

class LbPersonExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct(['model' => LbPerson::class, 'columns' => ['id' => 'int', 'name' => 'text', 'is_active' => 'bool']], $filter);
    }
}

class LbWideExport extends BaseExport
{
    public function __construct(array $filter)
    {
        $columns = ['id' => 'int', 'name' => 'text', 'is_active' => 'bool', 'created_at' => 'date', 'updated_at' => 'date'];
        foreach (['a', 'b', 'c'] as $extra) {
            $columns["name as {$extra}"] = 'text';
        }

        parent::__construct(['model' => LbPerson::class, 'columns' => array_slice($columns, 0, 5) + ['id_copy' => 'int', 'name_copy' => 'text', 'active_copy' => 'bool']], $filter);
    }
}

class LbCustomExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct(['model' => LbPerson::class, 'columns' => ['id' => 'int', 'name' => 'text', 'salary' => 'money']], $filter);
    }
}

class LbWhoamiExport extends BaseExport
{
    public static array $seenUsers = [];

    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => LbPerson::class,
            'columns' => ['id' => 'int'],
            'additionalQuery' => [function ($query) {
                self::$seenUsers[] = auth()->id();
            }],
        ], $filter);
    }
}

class LbAuthUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

class LbUser extends Authenticatable
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
