<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Support\Str;

/**
 * Handles polymorphic (morphTo) relation eager-loading and value extraction.
 *
 * Config shape in BaseExport:
 *
 *  'morph' => [
 *      'sourceable_id' => [            // FK column — used as heading key
 *          'relation' => 'sourceable', // Eloquent morphTo method name
 *          'column'   => 'name',       // Column to display in the export cell
 *          'type'     => 'text',       // Optional: convertValue type (default 'text')
 *          'fallback' => null,         // Optional: value when relation is null
 *      ],
 *  ],
 */
trait HasMorphRelations
{
    /**
     * Collect the _id and _type columns for all morph relations so they
     * are always included in the SELECT clause.
     */
    protected function resolveMorphTypeColumns(): array
    {
        $typeColumns = [];

        foreach ($this->relations['morph'] ?? [] as $morphConfig) {
            $typeColumns[] = $morphConfig['relation'] . '_id';
            $typeColumns[] = $morphConfig['relation'] . '_type';
        }

        return array_unique($typeColumns);
    }

    /**
     * Build the with() list for all morph relations.
     */
    protected function buildMorphWithRelations(): array
    {
        $withs = [];

        foreach ($this->relations['morph'] ?? [] as $morphConfig) {
            $withs[] = $morphConfig['relation'];
        }

        return $withs;
    }

    /**
     * Extract the display value from a morphTo relation on the given model instance.
     * Supports dot-notation column access for nested values (e.g. 'category.name').
     */
    protected function extractMorphRelation(mixed $object, string $foreignKey, array $morphConfig): mixed
    {
        $relationName = $morphConfig['relation'];
        $column       = $morphConfig['column'];
        $fallback     = $morphConfig['fallback'] ?? null;

        $related = $object->$relationName;

        if ($related === null) {
            return $fallback;
        }

        if (Str::contains($column, '.')) {
            return data_get($related, $column, $fallback);
        }

        return $this->convertValue($related, $column, $morphConfig['type'] ?? 'text');
    }
}
