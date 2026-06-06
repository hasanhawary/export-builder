<?php

namespace HasanHawary\ExportBuilder\Http\Controllers;

use HasanHawary\ExportBuilder\Http\Requests\ExportRequest;
use HasanHawary\ExportBuilder\Http\Resources\ExportFileResource;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $permissions = app(ExportPermissionResolver::class);

        abort_unless($permissions->canList($request->user()), 403);

        $query = ExportFile::query()->latest();

        // Scope visibility through the resolver — the single owner of this logic.
        // Custom resolver overrides via config are honored here automatically.
        $permissions->scopeForUser($query, $request->user());

        if ($request->filled('exportable_type')) {
            $query->where('exportable_type', $request->string('exportable_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json(
            ExportFileResource::collection($query->paginate($perPage))->response()->getData(true)
        );
    }

    public function export(ExportRequest $request, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        $filters = $request->validated();

        abort_unless($permissions->canCreateQueued($request->user(), $filters), 403);

        $export = $service->create($filters);

        ExportToFile::dispatch($export->id);

        return response()->json([
            'data'    => new ExportFileResource($export->refresh()),
            'message' => 'Export started successfully.',
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

    public function destroy(Request $request, int $exportFile, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        $export = ExportFile::findOrFail($exportFile);

        abort_unless($permissions->canDelete($request->user(), $export), 403);

        $service->delete($export);

        return response()->json(['message' => 'Export deleted successfully.']);
    }
}
