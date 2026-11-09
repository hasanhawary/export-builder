<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Facades\Export;
use HasanHawary\ExportBuilder\Http\Resources\ExportFileResource;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Support\ExportHelper;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\ExcelServiceProvider;
use Mccarlosen\LaravelMpdf\LaravelMpdfServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Covers: failed-job status, job limits, error exposure, chunked PDF data,
 * trash pruning, request limits, hidden-column filters, facade, translation lookup.
 */
class RobustnessTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExcelServiceProvider::class, LaravelMpdfServiceProvider::class, ExportBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.namespace', __NAMESPACE__);
        $app['config']->set('export.module.routes.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rb_people', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('secret')->nullable();
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

        foreach (['Ali', 'Badr', 'Cyrine', 'Dina', 'Ehab'] as $name) {
            RbPerson::create(['name' => $name, 'secret' => "s3cret-{$name}"]);
        }
    }

    private function export(array $attributes = []): ExportFile
    {
        return ExportFile::create(array_merge([
            'exportable_type' => 'rb_person', 'format' => 'xlsx', 'status' => ExportStatus::Processing->value,
            'disk' => 'local', 'created_by' => 1,
        ], $attributes));
    }

    private function failedEvent(object $command, ?\Throwable $e = null): JobFailed
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'data' => ['commandName' => $command::class, 'command' => serialize($command)],
        ]);

        return new JobFailed('database', $job, $e ?? new RuntimeException('Worker timed out'));
    }

    // ---------------------------------------------------------------------
    // Killed / timed-out jobs
    // ---------------------------------------------------------------------

    public function test_a_failed_export_job_marks_the_export_failed(): void
    {
        $export = $this->export();

        event($this->failedEvent(new ExportToFile($export->id)));

        $export->refresh();
        $this->assertSame(ExportStatus::Failed->value, $export->status);
        $this->assertSame('Worker timed out', $export->error_message);
    }

    public function test_a_failed_job_never_overwrites_a_completed_export(): void
    {
        $export = $this->export(['status' => ExportStatus::Completed->value]);

        event($this->failedEvent(new ExportToFile($export->id)));

        $this->assertSame(ExportStatus::Completed->value, $export->refresh()->status);
    }

    public function test_other_failed_jobs_are_ignored(): void
    {
        $export = $this->export();

        event($this->failedEvent(new RbOtherJob($export->id)));

        $this->assertSame(ExportStatus::Processing->value, $export->refresh()->status);
    }

    public function test_host_subclass_of_the_job_is_handled_too(): void
    {
        $export = $this->export();

        event($this->failedEvent(new RbHostExportJob($export->id)));

        $this->assertSame(ExportStatus::Failed->value, $export->refresh()->status);
    }

    public function test_unreadable_payload_is_ignored(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => ExportToFile::class, 'command' => 'encrypted:abc']]);

        event(new JobFailed('database', $job, new RuntimeException('x')));

        $this->assertTrue(true);
    }

    public function test_job_timeout_and_tries_come_from_config(): void
    {
        config()->set('export.module.queue.timeout', '900');
        config()->set('export.module.queue.tries', 2);

        $job = new ExportToFile(1);

        $this->assertSame(900, $job->timeout);
        $this->assertSame(2, $job->tries);
    }

    public function test_job_limits_default_to_the_worker_and_subclass_values_win(): void
    {
        $this->assertNull((new ExportToFile(1))->timeout);
        $this->assertNull((new ExportToFile(1))->tries);

        config()->set('export.module.queue.timeout', 900);
        $this->assertSame(60, (new RbHostExportJob(1))->timeout);
    }

    // ---------------------------------------------------------------------
    // Error exposure
    // ---------------------------------------------------------------------

    public function test_raw_error_is_hidden_from_the_api_unless_debug(): void
    {
        $export = $this->export(['status' => 'failed', 'error_message' => 'SQLSTATE[42S02]: Table secret_table missing']);

        config()->set('app.debug', false);
        $this->assertSame(
            'The export could not be created. Please try again.',
            (new ExportFileResource($export))->toArray(request())['error_message']
        );

        config()->set('app.debug', true);
        $this->assertStringContainsString('SQLSTATE', (new ExportFileResource($export))->toArray(request())['error_message']);

        config()->set('export.module.expose_errors', false);
        $this->assertStringNotContainsString('SQLSTATE', (new ExportFileResource($export))->toArray(request())['error_message']);

        config()->set('app.debug', false);
        config()->set('export.module.expose_errors', true);
        $this->assertStringContainsString('SQLSTATE', (new ExportFileResource($export))->toArray(request())['error_message']);

        $this->assertNull((new ExportFileResource($this->export()))->toArray(request())['error_message']);
    }

    // ---------------------------------------------------------------------
    // PDF data
    // ---------------------------------------------------------------------

    public function test_pdf_data_is_loaded_in_chunks_and_keeps_the_custom_order(): void
    {
        config()->set('export.chunk_size', 2);

        $rows = (new RbPersonDescExport([]))->pdfData()['rows'];

        $this->assertCount(5, $rows);
        $this->assertSame(['Ehab', 'Dina', 'Cyrine', 'Badr', 'Ali'], array_column($rows, 1));
    }

    // ---------------------------------------------------------------------
    // Trash pruning
    // ---------------------------------------------------------------------

    public function test_trash_is_pruned_after_the_configured_days_with_its_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/old.xlsx', 'x');
        Storage::disk('local')->put('exports/recent.xlsx', 'x');
        Storage::disk('local')->put('exports/active.xlsx', 'x');

        $old = $this->export(['file_path' => 'exports/old.xlsx']);
        $recent = $this->export(['file_path' => 'exports/recent.xlsx']);
        $active = $this->export(['file_path' => 'exports/active.xlsx']);

        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();
        $recent->delete();

        config()->set('export.module.trash.prune_after_days', 30);
        $this->artisan('model:prune', ['--model' => [ExportFile::class]])->assertSuccessful();

        $this->assertDatabaseMissing('export_files', ['id' => $old->id]);
        Storage::disk('local')->assertMissing('exports/old.xlsx');
        $this->assertSoftDeleted('export_files', ['id' => $recent->id]);
        Storage::disk('local')->assertExists('exports/recent.xlsx');
        $this->assertNotSoftDeleted('export_files', ['id' => $active->id]);
        Storage::disk('local')->assertExists('exports/active.xlsx');
    }

    public function test_nothing_is_pruned_when_trash_days_are_not_set(): void
    {
        $old = $this->export();
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subYears(2)])->saveQuietly();

        config()->set('export.module.trash.prune_after_days', null);
        $this->artisan('model:prune', ['--model' => [ExportFile::class]])->assertSuccessful();

        $this->assertSoftDeleted('export_files', ['id' => $old->id]);
    }

    public function test_pruning_is_scheduled_only_when_trash_days_are_set(): void
    {
        $commands = fn (Schedule $schedule) => collect($schedule->events())->map->command->filter()->implode(' | ');

        config()->set('export.module.trash.prune_after_days', 30);
        $this->app->forgetInstance(Schedule::class);
        $this->assertStringContainsString('model:prune', $commands(app(Schedule::class)));

        config()->set('export.module.trash.prune_after_days', null);
        $this->app->forgetInstance(Schedule::class);
        $this->assertStringNotContainsString('model:prune', $commands(app(Schedule::class)));
    }

    // ---------------------------------------------------------------------
    // Request limits / hidden columns
    // ---------------------------------------------------------------------

    public function test_huge_page_size_is_capped(): void
    {
        config()->set('export.module.max_per_page', 50);
        $this->app->instance('request', Request::create('/', 'GET', ['per_page' => '1000000']));

        $query = $this->getMockBuilder(Builder::class)->disableOriginalConstructor()->onlyMethods(['paginate'])->getMock();
        $query->expects($this->once())->method('paginate')->with(50)->willReturn(new LengthAwarePaginator([], 0, 50));

        wrapPaginate($query);
    }

    public function test_too_many_ids_in_one_request_is_422(): void
    {
        config()->set('export.module.max_bulk_ids', 3);
        app(ExportRoutes::class)->register();

        $this->actingAs(new RbUser(1))
            ->deleteJson('/api/export-log/delete', ['ids' => [1, 2, 3, 4]])
            ->assertStatus(422);
    }

    public function test_hidden_columns_cannot_be_used_as_advanced_filters(): void
    {
        $export = new RbPersonExport(['advanced' => [['key' => 'secret', 'value' => 's3cret-Ali']]]);

        // The filter on a hidden column is ignored: every row comes back, nothing can be guessed.
        $this->assertCount(5, iterator_to_array($export->collection()));

        $visible = new RbPersonExport(['advanced' => [['key' => 'name', 'value' => 'Ali']]]);
        $this->assertCount(1, iterator_to_array($visible->collection()));
    }

    // ---------------------------------------------------------------------
    // Facade / translations
    // ---------------------------------------------------------------------

    public function test_facade_make_builds_a_download(): void
    {
        $response = Export::make(['page' => 'rb_person', 'format' => 'csv'])->response();

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertStringContainsString('Ehab', file_get_contents($response->getFile()->getRealPath()));
    }

    public function test_translation_lookup_returns_null_for_missing_keys(): void
    {
        $this->assertNull(ExportHelper::lookup('definitely_missing_key', 'export'));
        $this->assertSame('definitely_missing_key', ExportHelper::resolveTrans('definitely_missing_key', 'export'));
        $this->assertSame('ID', (new RbPersonExport([]))->resolveTrans('ID'));
    }
}

class RbPerson extends Model
{
    protected $table = 'rb_people';

    protected $guarded = [];

    protected $hidden = ['secret'];
}

class RbPersonExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct(['model' => RbPerson::class, 'columns' => ['id' => 'int', 'name' => 'text']], $filter);
    }
}

class RbPersonDescExport extends BaseExport
{
    public function __construct(array $filter)
    {
        parent::__construct([
            'model' => RbPerson::class,
            'columns' => ['id' => 'int', 'name' => 'text'],
            'additionalQuery' => [fn ($query) => $query->orderByDesc('name')],
        ], $filter);
    }
}

class RbOtherJob
{
    public function __construct(public int $exportId) {}
}

class RbHostExportJob extends ExportToFile
{
    public $timeout = 60;
}

class RbUser extends Authenticatable
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
