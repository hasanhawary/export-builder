<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

trait AdvancedFilter
{
    /**
     * Optional enum resolver map defined by the subclass.
     * Shape: [ 'column_key' => ['enum' => MyEnum::class, 'method' => 'fromLabel'] ]
     *
     * @var array<string, array{enum: class-string, method: string}>
     */
    protected array $resolvers = [];

    /**
     * Static cache of schema column lists keyed by table name.
     * Prevents repeated SHOW COLUMNS / PRAGMA queries within a single request.
     *
     * @var array<string, list<string>>
     */
    private static array $columnListCache = [];

    /**
     * Apply advanced filters to the given Eloquent query.
     *
     * Unknown keys are silently ignored to prevent SQL errors from arbitrary input.
     * Relation keys are resolved via whereHas; column keys via whereIn.
     */
    public function applyAdvanced(Builder $query): static
    {
        $advancedFilters = $this->filter['advanced'] ?? [];

        if (empty($advancedFilters)) {
            return $this;
        }

        $relationMap    = $this->filterRelations['many'] ?? [];
        $allowedColumns = $this->getAllowedColumns($query);
        $allowedKeys    = array_merge(array_keys($relationMap), $allowedColumns);

        collect($advancedFilters)->each(function (array|object $item) use ($query, $relationMap, $allowedKeys): void {
            $key   = data_get($item, 'key');
            $value = Arr::wrap(data_get($item, 'value'));

            // Reject unknown keys — prevents arbitrary column injection
            if (! in_array($key, $allowedKeys, true)) {
                return;
            }

            // Optional enum resolver: transform raw value before filtering
            if (isset($this->resolvers[$key])) {
                $resolver = $this->resolvers[$key];
                $resolved = $resolver['enum']::{$resolver['method']}(data_get($item, 'value'));
                $value    = Arr::wrap($resolved);
            }

            if (empty($value)) {
                return;
            }

            try {
                if (array_key_exists($key, $relationMap)) {
                    $relation   = data_get($relationMap[$key], 'relation', $key);
                    $column     = data_get($relationMap[$key], 'column', 'id');
                    $morph      = data_get($relationMap[$key], 'morph');
                    $morphTypes = data_get($relationMap[$key], 'morph_types', []);

                    if ($morph && ! empty($morphTypes)) {
                        $query->whereHas(
                            $relation,
                            fn ($q) => $q->whereHasMorph(
                                $morph,
                                $morphTypes,
                                fn ($q2) => $q2->whereIn($column, $value)
                            )
                        );
                    } else {
                        $query->whereHas($relation, fn ($q) => $q->whereIn($column, $value));
                    }
                } else {
                    $query->whereIn($key, $value);
                }
            } catch (\Throwable $e) {
                // Log but continue — one bad filter key should not abort the whole export
                Log::warning('ExportBuilder: advanced filter skipped due to error', [
                    'key'     => $key,
                    'value'   => $value,
                    'message' => $e->getMessage(),
                ]);
            }
        });

        return $this;
    }

    /**
     * Return the actual column names for the query's table.
     * Result is cached statically per table name — schema doesn't change mid-request
     * and firing a SHOW COLUMNS / PRAGMA query on every filter application is wasteful.
     */
    private function getAllowedColumns(Builder $query): array
    {
        $table = $query->getModel()->getTable();

        if (! isset(self::$columnListCache[$table])) {
            self::$columnListCache[$table] = $query->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($table);
        }

        return self::$columnListCache[$table];
    }
}
