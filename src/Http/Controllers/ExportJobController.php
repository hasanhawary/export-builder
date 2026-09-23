<?php

namespace HasanHawary\ExportBuilder\Http\Controllers;

use HasanHawary\ExportBuilder\Concerns\HasDeleteMethods;
use HasanHawary\ExportBuilder\Concerns\OrderByFilter;
use HasanHawary\ExportBuilder\Concerns\SearchFilter;
use HasanHawary\ExportBuilder\Http\Requests\ExportRequest;
use HasanHawary\ExportBuilder\Http\Resources\ExportFileResource;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function eb_resolveTrans;
use function wrapPaginate;

class ExportJobController extends Controller
{
    use HasDeleteMethods;

    public function index(Request $request): JsonResponse
    {
        $permissions = app(ExportPermissionResolver::class);

        abort_unless($permissions->canList($request->user()), 403);

        $query = ExportFile::query();

        // Scope visibility through the resolver — the single owner of this logic.
        // Custom resolver overrides via config are honored here automatically.
        $permissions->scopeForUser($query, $request->user());

        if ($request->filled('exportable_type')) {
            $query->where('exportable_type', $request->string('exportable_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $query = app(Pipeline::class)
            ->send($query)
            ->through([SearchFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, ExportFileResource::class));
    }

    public function export(ExportRequest $request, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        $filters = $request->validated();

        abort_unless($permissions->canCreateQueued($request->user(), $filters), 403);

        $export = $service->create($filters);

        ExportToFile::dispatch($export->id);

        return response()->json([
            'data'    => new ExportFileResource($export->refresh()),
            'message' => eb_resolveTrans('export_started_successfully'),
        ], 202);
    }

    public function show(Request $request, int $exportFile, ExportPermissionResolver $permissions): JsonResponse
    {
        $export = ExportFile::findOrFail($exportFile);

        abort_unless($permissions->canView($request->user(), $export), 403);

        return response()->json(['data' => new ExportFileResource($export)]);
    }

    public function download(Request $request, int $exportFile, ExportPermissionResolver $permissions): StreamedResponse
    {
        $export = ExportFile::findOrFail($exportFile);

        abort_unless($permissions->canView($request->user(), $export), 403);
        abort_unless($export->file_path, 404);

        $disk = $export->disk ?: config('export.module.storage.disk', 'local');

        return Storage::disk($disk)->download($export->file_path, $export->file_name);
    }

}
