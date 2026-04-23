<?php

namespace HasanHawary\ExportBuilder;

use Illuminate\Support\Collection;

trait CustomRelationTrait
{
    private function handleCustomColumns($object, string $relation, array $attributes): array
    {
        $result = [];

        if (empty($object) || ($object instanceof Collection && $object->isEmpty())) {
            foreach ($attributes as $attr) {
                $result["{$relation}_{$attr}"] = '';
            }

            return $result;
        }

        if ($object instanceof Collection) {
            foreach ($object as $index => $model) {
                foreach ($attributes as $attr) {
                    $result["{$relation}_{$index}_{$attr}"] = strip_tags($model->{$attr} ?? '');
                }
            }
        } else {
            foreach ($attributes as $attr) {
                $result["{$relation}_{$attr}"] = strip_tags($object->{$attr} ?? '');
            }
        }

        return $result;
    }

    public function map($object): array
    {
        $custom = [];

        $base = collect(parent::map($object))
            ->reject(fn ($value, $key) => str_ends_with($key, '_id'))
            ->all();

        foreach ($this->customRelationsWithAttributes() as $relation => $attributes) {
            $relatedData = $this->handleCustomColumns($object->{$relation} ?? null, $relation, $attributes);
            $custom = array_merge($custom, $relatedData);
        }

        return array_merge($base, $custom);
    }

    public function headings(): array
    {
        $custom = [];

        $base = collect(parent::headings())
            ->reject(fn ($value, $key) => str_ends_with($value, '_id'))
            ->all();

        foreach ($this->customRelationsWithAttributes() as $relation => $attributes) {
            foreach ($attributes as $attr) {
                $custom[] = resolveTrans("{$relation}.{$attr}", 'export');
            }
        }

        return array_merge($base, $custom);
    }
}
