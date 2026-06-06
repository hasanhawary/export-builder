<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Handles eager-load resolution and data extraction for one-to-one
 * and one-to-many (concat / list / count) Eloquent relations.
 */
trait HasRelations
{
    /*
    |--------------------------------------------------------------------------
    | Eager-load path resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Convert a keyed relation map into a flat list of dot-notation with() paths.
     *
     * ['role_id' => ['role' => ['name' => 'text']]]
     * → ['role', 'role.department']   (if nested)
     */
    protected function resolveRelationKeys(array $relations): array
    {
        $selectedRelations = [];

        collect($relations)->each(function ($relationValue, $relationName) use (&$selectedRelations) {
            $selectedRelations[$relationName] = $this->guessRelationPattern($relationName, $relationValue);
        });

        return array_values($selectedRelations);
    }

    private function guessRelationPattern(string $relation, array $columns, ?string $parent = null): array|string
    {
        $relationPath = $parent ? "{$parent}.{$relation}" : $relation;
        $nested       = [$relationPath];

        collect($columns)
            ->filter(fn ($col) => \is_array($col))
            ->each(function ($nestedRelation) use (&$nested, $relationPath): void {
                collect($nestedRelation)->each(function ($relationValue, $relationName) use (&$nested, $relationPath): void {
                    $nested[] = $this->guessRelationPattern($relationName, $relationValue, $relationPath);
                });
            });

        return count($nested) === 1 ? $relationPath : Arr::flatten($nested);
    }

    /**
     * Detect BelongsTo foreign keys from a list of with() relation paths so they
     * are always included in the SELECT even when customSelect is in use.
     *
     * Result is cached per instance — the relation structure doesn't change within
     * a single export run, so there's no reason to reconstruct Eloquent relation
     * objects on every buildQuery() call.
     */
    protected function detectForeignKeys(array $relations): array
    {
        if ($this->detectedForeignKeys !== null) {
            return $this->detectedForeignKeys;
        }

        $keys  = [];
        $model = new $this->model;

        foreach ($relations as $relation) {
            $baseRelation = explode('.', $relation)[0];

            if (! method_exists($model, $baseRelation)) {
                continue;
            }

            try {
                $instance = $model->$baseRelation();

                if ($instance instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                    $keys[] = method_exists($instance, 'getForeignKeyName')
                        ? $instance->getForeignKeyName()
                        : $instance->getForeignKey();
                }
            } catch (\Throwable) {
                // Skip — relation may require constructor arguments we cannot provide
            }
        }

        return $this->detectedForeignKeys = array_filter(array_unique($keys));
    }

    /*
    |--------------------------------------------------------------------------
    | Data extraction
    |--------------------------------------------------------------------------
    */

    protected function extractOneRelation(mixed $parentEntity, array $relationMappings): array
    {
        $result = [];

        foreach ($relationMappings as $relationName => $columnMappings) {
            $relatedEntity = $parentEntity?->$relationName;

            foreach ($columnMappings as $columnName => $columnType) {
                $result[$relationName][$columnName] = \is_array($columnType)
                    ? $this->extractOneRelation($relatedEntity, $columnType)
                    : $this->convertValue($relatedEntity, $columnName, $columnType);
            }
        }

        return $result;
    }

    protected function extractManyRelation(mixed $object, string $relationName, array $details): array
    {
        $relatedDataSet = [];

        foreach ($object->$relationName as $relatedObject) {
            $relatedData = [];

            foreach ($details as $relatedColumn => $type) {
                if (\is_array($type)) {
                    foreach ($type as $nestedRelation => $nestedDetails) {
                        $relatedData[$nestedRelation] = Arr::first(
                            $this->extractNestedRelation($relatedObject, $nestedRelation, $nestedDetails)
                        );
                    }
                } else {
                    $relatedData[$relatedColumn] = $this->convertValue($relatedObject, $relatedColumn, $type);
                }
            }

            $relatedDataSet[] = $relatedData;
        }

        return $relatedDataSet;
    }

    private function extractNestedRelation(mixed $relatedObject, ?string $nestedRelation, ?array $nestedDetails): array
    {
        $value = $relatedObject?->$nestedRelation;

        if (\is_array($value) && count($value) > 0 && \is_object($value[0])) {
            return $this->extractManyRelation($relatedObject, $nestedRelation, $nestedDetails);
        }

        return $this->extractOneRelation($relatedObject, [$nestedRelation => $nestedDetails]);
    }

    /*
    |--------------------------------------------------------------------------
    | map() helpers for one / many relations
    |--------------------------------------------------------------------------
    */

    /**
     * Extract the scalar display value from a one-to-one relation result.
     * Strips nested '_id' keys keeping only the displayable leaf value.
     */
    protected function extractOneRelationValue(mixed $object, array $relation): mixed
    {
        $oneRelation = $this->extractOneRelation($object, $relation);

        return current(Arr::where(Arr::dot($oneRelation), function ($value, $key) {
            $parts = Str::of($key)->explode('.');

            return ($parts->last() === 'id' && $parts->count() === 1)
                || $parts->last() !== 'id';
        }));
    }

    /**
     * Extract and concatenate values from a one-to-many (concat) relation.
     */
    protected function extractConcatRelationValue(mixed $object, string $relation, array $details): string
    {
        $many = $this->extractManyRelation($object, $relation, $details);

        return implode(', ', Arr::where(Arr::dot($many), function ($value, $key) {
            $parts = Str::of($key)->explode('.');

            return ($parts->last() === 'id' && $parts->count() === 1)
                || $parts->last() !== 'id';
        }));
    }
}
