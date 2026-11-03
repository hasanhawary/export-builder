<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class ExportFileFilter
{
    public function handle(Builder $query, Closure $next): Builder
    {
        $this->applyFilter($query, 'exportable_type', request('exportable_type', $this->pageAlias()));
        $this->applyFilter($query, 'status', request('status'));

        return $next($query);
    }

    /**
     * "page" is the key used when the export is created, so accept it as an alias for exportable_type.
     * Numeric values are left alone because paginate() uses "page" as the page number.
     */
    protected function pageAlias(): mixed
    {
        $page = request('page');

        return is_numeric($page) ? null : $page;
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
