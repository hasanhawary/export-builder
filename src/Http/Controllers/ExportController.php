<?php

namespace HasanHawary\ExportBuilder\Http\Controllers;

use HasanHawary\ExportBuilder\Http\Requests\ExportRequest;
use HasanHawary\ExportBuilder\Services\ExportPermissionResolver;
use HasanHawary\ExportBuilder\Services\ExportService;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __invoke(ExportRequest $request): BinaryFileResponse
    {
        $filters = $this->filters($request);

        abort_unless(app(ExportPermissionResolver::class)->canExport($request->user(), $filters), 403);

        return app(ExportService::class)->response($filters);
    }

    private function filters(ExportRequest $request): array
    {
        return $request->validated();
    }
}
