<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\AbstractPaginator;

/*
 * Fallbacks for apps that do not define these helpers themselves.
 *
 * Loaded by ExportBuilderServiceProvider::register(), i.e. after Composer has included
 * the app's own helper files, so an app's wrapPaginate() / successResponse() always win.
 * (Loaded through composer "files" they would be defined first and replace the app's.)
 * The package itself does not use them.
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
            // Cap huge page sizes (per_page=1000000); -1 above stays the explicit "everything".
            $perPage = min((int) $requested, max(1, (int) config('export.module.max_per_page', 500)));
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
