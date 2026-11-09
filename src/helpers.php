<?php

use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\AbstractPaginator;

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

/*
 * wrapPaginate() / successResponse() are only fallbacks: when the host app defines its
 * own versions (common), those are used and the response shape stays the app's own.
 */
if (! function_exists('wrapPaginate')) {
    /**
     * per_page: a positive integer pages, -1 returns everything, anything else uses
     * config('project.pagination.per_page') (or everything when that is not set).
     */
    function wrapPaginate(Builder|QueryBuilder $query, ?string $resource = null, array $meta = [])
    {
        $requested = request('per_page');
        $default   = (int) config('project.pagination.per_page');

        if (is_scalar($requested) && (string) $requested === '-1') {
            $perPage = null;
        } elseif (is_scalar($requested) && ctype_digit((string) $requested) && (int) $requested > 0) {
            $perPage = (int) $requested;
        } else {
            $perPage = $default > 0 ? $default : null;
        }

        if ($perPage) {
            $data = $query->paginate($perPage);

            // Keep the paginator shape, but transform each item through the resource.
            if ($resource && $data instanceof AbstractPaginator) {
                $data->through(fn ($item) => (new $resource($item))->resolve());
            }
        } else {
            $data = $resource ? $resource::collection($query->get()) : $query->get();
        }

        if (count($meta)) {
            // Metadata is extra information; it can never replace the data itself.
            $data = array_merge($meta, ['data' => $data]);
        }

        return $data;
    }
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
