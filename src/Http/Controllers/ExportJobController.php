<?php

namespace HasanHawary\ExportBuilder\Http\Controllers;

use HasanHawary\ExportBuilder\Concerns\ExportFileFilter;
use HasanHawary\ExportBuilder\Concerns\HasDeleteMethods;
use HasanHawary\ExportBuilder\Concerns\OrderByFilter;
use HasanHawary\ExportBuilder\Concerns\SearchFilter;
use HasanHawary\ExportBuilder\Http\Requests\ExportRequest;
use HasanHawary\ExportBuilder\Http\Resources\ExportFileResource;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function eb_resolveTrans;

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

        $query = app(Pipeline::class)
            ->send($query)
            ->through([ExportFileFilter::class, SearchFilter::class, OrderByFilter::class])
            ->thenReturn();

        return response()->json($this->listResponse($query));
    }

    /**
     * Same shape as 2.4.3: { data, links, meta } (just { data } for per_page=-1).
     * Built here on purpose: the app's global wrapPaginate()/successResponse() may differ.
     */
    protected function listResponse(Builder $query): array
    {
        $requested = request('per_page');

        if (is_scalar($requested) && (string) $requested === '-1') {
            return ExportFileResource::collection($query->get())->response()->getData(true);
        }

        $perPage = is_scalar($requested) && ctype_digit((string) $requested) && (int) $requested > 0
            ? min((int) $requested, max(1, (int) config('export.module.max_per_page', 100)))
            : 15;

        return ExportFileResource::collection($query->paginate($perPage))->response()->getData(true);
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

    // Signature kept as-is: host apps override this method in subclasses.
    public function download(Request $request, int $exportFile, ExportPermissionResolver $permissions): StreamedResponse
    {
        $export = ExportFile::findOrFail($exportFile);

        abort_unless($permissions->canView($request->user(), $export), 403);
        abort_unless($export->file_path, 404);

        $disk = Storage::disk(app(ExportFileService::class)->diskFor($export));

        // The record can outlive its file (cleaned-up disk, moved storage): 404, not 500.
        abort_unless($disk->exists($export->file_path), 404);

        return $disk->download($export->file_path, $export->file_name ?: basename($export->file_path));
    }

    /**
     * DELETE export-log/{id}: move one export to the trash.
     * Signature kept from 2.4.3: host apps override this method in subclasses.
     */
    public function destroy(Request $request, int $exportFile, ExportFileService $service, ExportPermissionResolver $permissions): JsonResponse
    {
        $export = ExportFile::findOrFail($exportFile);

        abort_unless($permissions->canDelete($request->user(), $export), 403, eb_resolveTrans('not_allowed_to_delete'));

        $service->delete($export);

        return response()->json(['message' => eb_resolveTrans('export_deleted_successfully')]);
    }

    /**
     * DELETE export-log/delete with { "ids": [...] }: move several exports to the trash.
     */
    public function destroyMany(): JsonResponse
    {
        return $this->handleDelete('delete');
    }

}
