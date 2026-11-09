<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class ExportFileFilter
{
    public function handle(Builder $query, Closure $next): Builder
    {
        // "type" is a short alias for exportable_type ("page" is left for pagination).
        $this->applyFilter($query, 'exportable_type', request('exportable_type', request('type')));
        $this->applyFilter($query, 'status', request('status'));
        $this->applyFilter($query, 'format', request('format'));
        $this->applyFilter($query, 'created_by', request('created_by'));

        // Date range on created_at, e.g. start=2026-01-01&end=2026-01-31 (both inclusive).
        $this->applyDate($query, '>=', request('start'), startOfDay: true);
        $this->applyDate($query, '<=', request('end'), startOfDay: false);

        // Trash: trashed=only (the trash) or trashed=with (everything). Default: active exports.
        match (request('trashed')) {
            'only'  => $query->onlyTrashed(),
            'with'  => $query->withTrashed(),
            default => null,
        };

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

    /**
     * Invalid dates are ignored instead of failing the request.
     */
    protected function applyDate(Builder $query, string $operator, mixed $value, bool $startOfDay): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $date = Carbon::parse(trim($value));
        } catch (Throwable) {
            return;
        }

        $date = $startOfDay ? $date->startOfDay() : $date->endOfDay();

        $query->where($query->getModel()->qualifyColumn('created_at'), $operator, $date);
    }
}
