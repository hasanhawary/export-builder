<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class ExportFileFilter
{
    public function handle(Builder $query, Closure $next): Builder
    {
        // "type" is a short alias for exportable_type ("page" is left for pagination).
        $this->applyFilter($query, 'exportable_type', request('exportable_type', request('type')));
        $this->applyFilter($query, 'status', request('status'));

        return $next($query);
    }

    /**
     * Accepts a single value, an array, or a comma-separated list (e.g. status=pending,failed).
     */
    protected function applyFilter(Builder $query, string $column, mixed $value): void
    {
        $values = collect(is_array($value) ? $value : explode(',', (string) $value))
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn (string $item) => $item !== '')
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        $column = $query->getModel()->qualifyColumn($column);

        $values->count() === 1
            ? $query->where($column, $values->first())
            : $query->whereIn($column, $values->all());
    }
}
