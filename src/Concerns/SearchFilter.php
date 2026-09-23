<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class SearchFilter
{
    public function handle(Builder $query, Closure $next): Builder
    {
        $search = request('search');

        if (is_string($search) && trim($search) !== '') {
            $search = '%'.trim($search).'%';

            // Group alternatives so they cannot bypass ownership or other filters.
            $query->where(function (Builder $query) use ($search): void {
                foreach (['id', 'file_name', 'exportable_type', 'format', 'status'] as $column) {
                    $query->orWhere($query->getModel()->qualifyColumn($column), 'like', $search);
                }
            });
        }

        return $next($query);
    }
}
