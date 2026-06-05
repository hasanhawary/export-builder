<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;

/**
 * Builds the base Eloquent query shared by both Excel (collection()) and PDF (pdfData()).
 * Single source of truth for query construction — relations, eager loads, filters, scopes.
 */
trait HasQueryBuilder
{
    /**
     * Cached Builder instance — built once per export instance.
     * Both collection() and pdfData() call buildQuery(); caching ensures
     * the query is assembled only once even if both are called.
     */
    private ?Builder $builtQuery = null;

    /**
     * Assemble and return the fully-configured Eloquent query.
     *
     * Result is cached on the instance — calling buildQuery() twice on the same
     * export object returns the same Builder without re-assembling all the eager
     * loads, filters, and column selects a second time.
     */
    protected function buildQuery(): Builder
    {
        if ($this->builtQuery !== null) {
            return $this->builtQuery;
        }

        // --- Eager-load paths --------------------------------------------------
        $eagerLoads = $this->resolveRelationKeys($this->mergeKeyedArrays($this->relations['one']));
        $eagerLoads = array_merge(
            $eagerLoads,
            $this->resolveRelationKeys($this->applyColumnFilter($this->relations['many']['concat'], 'many')),
            $this->resolveRelationKeys($this->relations['many']['list']),
            $this->customWith
        );

        $customRelationKeys = array_keys($this->customRelations());
        if (! empty($customRelationKeys)) {
            $eagerLoads = array_merge($eagerLoads, $customRelationKeys);
        }

        $countRelations = $this->relations['many']['count'];
        $morphLoads     = $this->buildMorphWithRelations();

        // --- SELECT columns ----------------------------------------------------
        $selectColumns = array_unique(array_merge(
            array_keys($this->columns),
            array_keys($this->relations['one']),
            $this->customSelect,
            $this->resolveMorphTypeColumns(),
            $this->detectForeignKeys($eagerLoads)
        ));

        // --- Assemble query ----------------------------------------------------
        $query = $this->model::select($selectColumns);

        if (! empty($countRelations)) {
            $query->withCount($countRelations);
        }

        if (! empty($eagerLoads)) {
            $query->with(...Arr::flatten($eagerLoads));
        }

        if (! empty($morphLoads)) {
            $query->with($morphLoads);
        }

        collect($this->additionalQuery)->each(fn ($closure) => $closure($query));

        $this->applyDateFilter($query);
        $this->applyAdvancedFilter($query);

        return $this->builtQuery = $query;
    }

    /**
     * Stream all rows as a LazyCollection via lazyById().
     *
     * lazyById() uses keyset pagination (WHERE id > last_seen_id) rather than
     * an unbuffered server-side cursor, which is safer for large tables on MySQL
     * and avoids holding a long-lived DB connection open.
     *
     * Chunk size is configurable via config('export.chunk_size', 500).
     * maatwebsite/excel (FromCollection) iterates lazily — peak memory stays
     * flat regardless of dataset size.
     */
    public function collection(): LazyCollection
    {
        $chunkSize = (int) config('export.chunk_size', 500);

        return $this->buildQuery()->lazyById($chunkSize);
    }
}
