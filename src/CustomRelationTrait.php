<?php

namespace HasanHawary\ExportBuilder;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;

trait CustomRelationTrait
{
    /**
     * @return array
     */
    public function customRelations(): array
    {
        return [];
    }
    
    public function mapRelations($object): array
    {
        $customResults = [];

        foreach ($this->customRelations() as $relation => $attributes) {
            $customResults[] = $this->handleCustomColumns(data_get($object, $relation), $relation, (array) $attributes);
        }

        return !empty($customResults) ? array_merge(...$customResults) : [];
    }

    public function headingRelations(): array
    {
        $custom = [];

        foreach ($this->customRelations() as $relation => $attributes) {
            foreach ($attributes as $key => $attr) {
                $columnName = is_numeric($key) ? $attr : $key;
                $relationKey = str_replace('.', '_', $relation);
                $custom[] = $this->resolveTrans("{$relationKey}_{$columnName}");
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
