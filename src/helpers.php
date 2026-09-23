<?php

use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;

/**
 * Global function aliases for ExportHelper static methods.
 *
 * Kept for backwards compatibility. Prefer calling ExportHelper directly
 * in new code: ExportHelper::isIndexedArray() / ExportHelper::resolveTrans()
 */
if (! function_exists('eb_isArrayIndex')) {
    function eb_isArrayIndex(mixed $value): bool
    {
        return ExportHelper::isIndexedArray($value);
    }
}

if (! function_exists('eb_resolveTrans')) {
    function eb_resolveTrans(
        mixed   $trans  = '',
        string  $page   = 'export',
        ?string $lang   = null,
        bool    $snaked = true
    ): string {
        return ExportHelper::resolveTrans($trans, $page, $lang, $snaked);
    }
}

if (! function_exists('wrapPaginate')) {
    function wrapPaginate(Builder|QueryBuilder $query, ?string $resource = null, array $meta = [])
    {
        $perPage = request('per_page', config('project.pagination.per_page'));

        if ($perPage && (int) $perPage !== -1) {
            $data = $query->paginate($perPage);

            if ($resource) {
                $data->data = $resource::collection($data);
            }
        } else {
            $data = $resource ? $resource::collection($query->get()) : $query->get();
        }

        if (count($meta)) {
            $data = [
                'data' => $data,
                ...$meta,
            ];
        }

        return $data;
    }

    if (! function_exists('successResponse')) {
    function successResponse($data = [], $msg = null, $code = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'code' => $code,
            'message' => $msg ?? __('api.success'),
            'data' => $data,
        ], $code);
    }
}
}
