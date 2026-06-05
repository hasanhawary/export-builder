<?php

namespace HasanHawary\ExportBuilder\Concerns;

use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Support\Arr;

/**
 * Handles column / related-column selection filtering and date-range filtering.
 *
 * When a request includes 'columns' or 'related' arrays, only the requested
 * keys survive — everything else is stripped before headings and map() run.
 */
trait HasColumnFilter
{
    /**
     * Slice an array down to only the keys (or values for indexed arrays) the
     * request has asked for.
     *
     * @param  string  $type  'column' for base columns / one-relations,
     *                        'many' for concat / list / count / additionalQuery
     */
    protected function applyColumnFilter(array $nativeArray, string $type = 'column'): array
    {
        $hasColumnFilter  = ! empty($this->filter['columns']);
        $hasRelatedFilter = ! empty($this->filter['related']);

        if (! $hasColumnFilter && ! $hasRelatedFilter) {
            return $nativeArray;
        }

        // Associative arrays — filter by key
        if (! ExportHelper::isIndexedArray($nativeArray)) {
            return $type === 'many'
                ? Arr::only($nativeArray, $this->filter['related'] ?? [])
                : Arr::only($nativeArray, $this->filter['columns'] ?? []);
        }

        // Indexed arrays (e.g. count relations like 'posts as posts_total') — filter by value
        if ($type === 'many') {
            return array_values(
                array_filter($nativeArray, fn ($value) => in_array($value, $this->filter['related'] ?? [], true))
            );
        }

        return $nativeArray;
    }

    /**
     * Apply date-range constraints from filter['start'] / filter['end']
     * against the configured dateColumn.
     */
    public function applyDateFilter(\Illuminate\Database\Eloquent\Builder $query): void
    {
        if (! empty($this->filter['start'])) {
            $query->whereDate($this->dateColumn, '>=', $this->filter['start']);
        }

        if (! empty($this->filter['end'])) {
            $query->whereDate($this->dateColumn, '<=', $this->filter['end']);
        }
    }

    /**
     * Hook called by buildQuery(). Delegates to AdvancedFilter::applyAdvanced().
     *
     * Subclasses may override this to take full control of all query filtering.
     * When overriding, you are responsible for calling applyDateFilter() yourself
     * if you still want date-range support.
     */
    public function applyAdvancedFilter(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $this->applyAdvanced($query);
    }
}
