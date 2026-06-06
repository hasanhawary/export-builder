<?php

namespace HasanHawary\ExportBuilder\Concerns;

use Illuminate\Support\Collection;

trait CustomRelationTrait
{
    public function customRelations(): array
    {
        return [];
    }

    public function mapRelations($object): array
    {
        $customResults = [];
        $hasFilter = ! empty($this->filter['columns']) || ! empty($this->filter['related']);

        foreach ($this->customRelations() as $relation => $attributes) {
            $filteredAttributes = [];

            foreach ((array) $attributes as $key => $attr) {
                $columnName = is_numeric($key) ? $attr : $key;
                $relationKey = str_replace('.', '_', $relation);
                $fullKey = "{$relationKey}_{$columnName}";

                if ($hasFilter && ! in_array($fullKey, $this->filter['related'] ?? [], true)) {
                    continue;
                }

                $filteredAttributes[$key] = $attr;
            }

            if (! empty($filteredAttributes)) {
                $customResults[] = $this->handleCustomColumns(data_get($object, $relation), $relation, $filteredAttributes);
            }
        }

        return ! empty($customResults) ? array_merge(...$customResults) : [];
    }

    public function headingRelations(): array
    {
        $custom = [];
        $hasFilter = ! empty($this->filter['columns']) || ! empty($this->filter['related']);

        foreach ($this->customRelations() as $relation => $attributes) {
            foreach ($attributes as $key => $attr) {
                $columnName = is_numeric($key) ? $attr : $key;
                $relationKey = str_replace('.', '_', $relation);
                $fullKey = "{$relationKey}_{$columnName}";

                if ($hasFilter && ! in_array($fullKey, $this->filter['related'] ?? [], true)) {
                    continue;
                }

                $custom[] = $this->resolveTrans($fullKey);
            }
        }

        return $custom;
    }

    private function handleCustomColumns($object, string $relation, array $attributes): array
    {
        $result = [];
        $relationKey = str_replace('.', '_', $relation);

        if (empty($object) || ($object instanceof Collection && $object->isEmpty())) {
            foreach ($attributes as $key => $attr) {
                $columnName = is_numeric($key) ? $attr : $key;
                $result["{$relationKey}_{$columnName}"] = '';
            }

            return $result;
        }

        if ($object instanceof Collection) {
            foreach ($object as $index => $model) {
                foreach ($attributes as $key => $attr) {
                    $columnName = is_numeric($key) ? $attr : $key;
                    $value = is_numeric($key)
                        ? ($model->{$attr} ?? '')
                        : (is_callable($attr) ? $attr($model) : ($model->{$attr} ?? ''));

                    $result["{$relationKey}_{$index}_{$columnName}"] = strip_tags($value ?? '');
                }
            }
        } else {
            foreach ($attributes as $key => $attr) {
                $columnName = is_numeric($key) ? $attr : $key;
                $value = is_numeric($key)
                    ? ($object->{$attr} ?? '')
                    : (is_callable($attr) ? $attr($object) : ($object->{$attr} ?? ''));

                $result["{$relationKey}_{$columnName}"] = strip_tags($value ?? '');
            }
        }

        return $result;
    }
}
