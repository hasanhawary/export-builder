<?php

namespace HasanHawary\ExportBuilder\Tests;

use HasanHawary\ExportBuilder\ExportBuilderServiceProvider;
use HasanHawary\ExportBuilder\Http\Controllers\ExportJobController;
use HasanHawary\ExportBuilder\Http\Requests\ExportRequest;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use HasanHawary\ExportBuilder\Support\ExportRoutes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Host apps extend the package controller and job and override methods with the
 * 2.4.3 signatures. This file fails to load if any of those signatures break.
 */
class BackwardCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ExportBuilderServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('export.module.routes.middleware', []);
        $app['config']->set('export.module.controllers.jobs', LegacyHostExportJobController::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

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

    private function export(int $owner = 1): ExportFile
    {
        return ExportFile::create([
            'exportable_type' => 'user', 'created_by' => $owner, 'format' => 'xlsx',
            'status' => 'completed', 'disk' => 'local', 'file_path' => 'exports/legacy.xlsx', 'file_name' => 'legacy.xlsx',
        ]);
    }

    public function test_host_controller_with_243_signatures_loads_and_serves_the_routes(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/legacy.xlsx', 'content');
        $export = $this->export();

        $user = new CompatUser(1);

        $this->actingAs($user)->getJson("/api/export-log/{$export->id}")->assertOk()->assertJsonPath('host', true);
        $this->actingAs($user)->get("/api/export-log/{$export->id}/download")->assertOk();
        $this->actingAs($user)->deleteJson("/api/export-log/{$export->id}")->assertOk()->assertJsonPath('host', true);

        $this->assertSoftDeleted('export_files', ['id' => $export->id]);
    }

    public function test_243_single_delete_route_still_works_with_the_package_controller(): void
    {
        config()->set('export.module.controllers.jobs', ExportJobController::class);
        config()->set('export.module.routes.prefix', 'v243');
        config()->set('export.module.routes.name_prefix', 'v243.');
        app(ExportRoutes::class)->register();
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

        Storage::fake('local');
        Storage::disk('local')->put('exports/legacy.xlsx', 'content');
        $export = $this->export();

        $this->actingAs(new CompatUser(2))->deleteJson("/v243/export-log/{$export->id}")->assertForbidden();
        $this->actingAs(new CompatUser(1))->deleteJson("/v243/export-log/{$export->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Export deleted successfully.');

        $this->assertSoftDeleted('export_files', ['id' => $export->id]);
        Storage::disk('local')->assertExists('exports/legacy.xlsx');
    }

    public function test_host_job_overriding_handle_still_works(): void
    {
        $job = new LegacyHostExportToFile(123);

        $this->assertSame(123, $job->exportId);
        $this->assertInstanceOf(ExportToFile::class, $job);
    }
}

/** Overrides with exactly the 2.4.3 signatures (as hosts like nwc-vms do). */
class LegacyHostExportJobController extends ExportJobController
{
    public function index(Request $request): JsonResponse
    {
        return parent::index($request);
    }

    public function show(Request $request, int $exportFile, ExportPermissionResolver $permissions): JsonResponse
    {
        $response = parent::show($request, $exportFile, $permissions);

        return response()->json([...$response->getData(true), 'host' => true]);
    }

    public function download(Request $request, int $exportFile, ExportPermissionResolver $permissions): StreamedResponse
    {
        return parent::download($request, $exportFile, $permissions);
    }

    public function destroy(Request $request, int $exportFile, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        $response = parent::destroy($request, $exportFile, $service, $permissions);

        return response()->json([...$response->getData(true), 'host' => true]);
    }

    public function export(ExportRequest $request, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        return parent::export($request, $service, $permissions);
    }
}

class LegacyHostExportToFile extends ExportToFile
{
    public function handle(ExportFileService $service): void
    {
        parent::handle($service);
    }
}

class CompatUser extends Authenticatable
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
