<?php

namespace HasanHawary\ExportBuilder\Types;


use HasanHawary\ExportBuilder\HelperTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

abstract class BaseExport implements FromArray, WithMapping, WithHeadings
{
    use HelperTrait;

    private array $columns;
    private array $relations;
    private array $additionalQuery;
    private string|array $model;
    private string $dateColumn;
    private array $filter;
    private array $customWith;

    public function __construct(array $config, array $filter)
    {
        /**
         * Columns Configuration:
         *
         * Define the structure and data types of the columns associated with a 'cause'.
         * Each entry in the array represents a column in your dataset.
         * The key is the column name, and the value is the data type of that column.
         *
         * Supported data types include:
         * - 'text': for text-based columns.
         * - 'date': for date columns.
         * - Enum classes: for columns that should be an enum value (e.g., CauseStatusEnum::class).
         * - 'file': for file or binary data columns.
         * - 'bool': for boolean columns.
         *
         * Example:
         * To add a 'description' column of type 'text', add the following key-value pair:
         * 'description' => 'text',
         */
        $this->columns = $config['columns'];

        /**
         * Relations Configuration:
         *
         * Define the relationships between 'cause' and other entities.
         * Relationships are categorized into 'one' (one-to-one) and 'many' (one-to-many).
         *
         * For one-to-one relationships, use the 'one' key, and for one-to-many relationships, use the 'many' key.
         *
         * Each relationship should be an array where the key is the relationship name and the value is an array defining the structure of the related entity.
         *
         * For nested relations, the structure can be nested arrays.
         *
         * Example:
         * To add a one-to-one relationship with an entity 'category' which has a 'name' column, add the following:
         * 'one' => [
         *     'category_id' => ['category' => ['name' => 'text']]
         * ],
         * To add a one-to-many relationship with an entity 'tags' which has a 'label' column, add the following:
         * 'many' => [
         *     'tags' => ['label' => 'text']
         * ],
         */
        $this->relations = $config['relations'] ?? [];

        /**
         * Custom With (Eager Loading) Configuration:
         *
         * Define additional relationships to eager load on the base query.
         * Use this to prevent N+1 queries or to prepare nested data that may not
         * be explicitly described in the 'relations' configuration above.
         *
         * This must be an array compatible with Eloquent's with() method.
         *
         * Supported forms:
         * - Simple list of relationship names:
         *   ['category', 'creator']
         *
         * - Nested relationships using dot or array notation:
         *   ['category.parent', 'tags']
         *   or
         *   ['category' => ['parent'], 'tags']
         *
         * - Constrained relationships using closures:
         *   ['comments' => function ($query) { $query->latest()->limit(5); }]
         *
         * Examples:
         * To eager load 'category':
         * ['category']
         *
         * To eager load nested 'category.parent' and only approved 'comments':
         * [
         *     'category.parent',
         *     'comments' => fn ($query) => $query->where('approved', true),
         * ]
         */
        $this->customWith = $config['customWith'] ?? [];

        /**
         * Additional Query Configuration:
         *
         * Define additional queries or helper queries that can be used in the 'with' method when retrieving the 'cause' entity.
         * Each query should be an array where the key is the query name, and the value is a closure defining the structure of the query.
         *
         * For example,
         * To add a query named 'example_query' which performs a specific operation, add the following:
         * 'name_column' => function ($query) {
         *     // Define the structure of your query here
         *     $query->with{Aggregate}(['relations' => Closure()]);
         * },
         */
        $this->additionalQuery = $config['additionalQuery'] ?? [];

        /**
         * Model Configuration:
         *
         * Set the model for the entity based on the provided configuration.
         */
        $this->model = $config['model'];


        /**
         * Date Column Configuration:
         *
         * Set the date column based on the provided configuration.
         */
        $this->dateColumn = $config['date_column'] ?? 'created_at';

        /**
         * Filter Configuration:
         *
         * Set the filter based on the provided configuration.
         */
        $this->filter = $filter;
    }

    /**.
     * @return array
     */
    public function array(): array
    {
        $typedObject = is_array($this->model)
            ? collect($this->model)
            : (new $this->model)
                ->when(!empty($this->customWith), fn($q) => $q->with($this->customWith))
                ->when(!empty($this->relations['one']), function ($q) {
                    foreach ($this->relations['one'] as $id => $relation) {
                        $relation = array_key_first($relation);
                        if ($relation) {
                            $q->with($relation);
                        }
                    }
                })
                ->when(!empty($this->relations['many']), fn($q) => $q->with(array_keys($this->relations['many'])))
                ->when(isset($this->filter['apply_date']) && $this->filter['apply_date'] && !empty($this->dateColumn), function ($q) {
                    if (!empty($this->filter['start'])) {
                        $q->whereDate($this->dateColumn, '>=', $this->filter['start']);
                    }
                    if (!empty($this->filter['end'])) {
                        $q->whereDate($this->dateColumn, '<=', $this->filter['end']);
                    }
                })
                ->when(!empty($this->filter['search']), function ($q) {
                    $q->where(function ($q) {
                        $searchColumns = Arr::where(array_keys($this->columns), fn($k) => !Str::contains($k, '.'));
                        foreach ($searchColumns as $index => $column) {
                            $q->orWhere($column, 'like', "%{$this->filter['search']}%");
                        }
                    });
                })
                ->when(!empty($this->additionalQuery), function ($q) {
                    collect($this->additionalQuery)->each(function ($additional, $key) use ($q) {
                        $q->$key($additional);
                    });
                })
                ->when(isset($this->filter['conditions']) && !empty($this->filter['conditions']), fn($q) => $this->applyConditionFilter($q))
                ->when(!empty($this->filter['order_by']), function ($q) {
                    $q->orderBy($this->filter['order_by'], $this->filter['order_dir'] ?? 'asc');
                })
                ->when(!empty($this->filter['limit']) && is_numeric($this->filter['limit']), function ($q) {
                    $q->limit((int)$this->filter['limit']);
                })
                ->get();

        $oneRelations = $this->extractOneRelation($typedObject, $this->relations['one'] ?? []);

        $manyRelations = [];
        if (!empty($this->relations['many'])) {
            foreach ($this->relations['many'] as $relationName => $details) {
                $manyRelations[$relationName] = $this->extractManyRelation($typedObject, $relationName, $details);
            }
        }

        $nativeArray = array_map(function ($object) use ($oneRelations, $manyRelations) {
            if (!empty($oneRelations)) {
                $object = array_merge($object, $this->mergeKeyedArrays($oneRelations[$object['id']] ?? []));
            }

            if (!empty($manyRelations)) {
                $object = array_merge($object, $this->flattenArray($manyRelations[$object['id']] ?? []));
            }

            return $object;
        }, $typedObject->toArray());

        return $this->applyColumnFilter($nativeArray, $this->filter['type'] ?? 'all');
    }

    public function map($object): array
    {
        $data = [];

        foreach ($this->columns as $column => $type) {
            // If a column path is nested (dot notation), keep the configured scalar type and let convertValue resolve the nested value.
            $data[] = $this->convertValue((object)$object, $column, $type);
        }

        return $data;
    }

    public function headings(): array
    {
        $keys = array_keys($this->columns);
        return collect($keys)
            ->map(fn($item) => Str::replace('.', '_', $item))
            ->map(fn($item) => $this->resolveTrans($item))
            ->toArray();
    }

    public function isEnabled(): true
    {
        //Override and add your permission in every class
        return true;
    }

    private function extractOneRelation(mixed $parentEntity, array $relationMappings): array
    {
        $result = [];

        if (empty($relationMappings)) {
            return $result;
        }

        foreach ($relationMappings as $foreignKey => $relationMapping) {
            $relationName = array_key_first($relationMapping);
            $columns = $relationMapping[$relationName] ?? [];

            foreach ($parentEntity->toArray() as $parent) {
                $relatedObject = $parent[$relationName] ?? null;

                if ($relatedObject) {
                    $parentId = $parent['id'];
                    $result[$parentId][] = collect($columns)
                        ->mapWithKeys(function ($type, $column) use ($relatedObject, $relationName) {
                            $key = $relationName . '_' . $column;
                            return [$key => $this->convertValue((object)$relatedObject, $column, $type)];
                        })
                        ->toArray();
                }
            }
        }

        return $result;
    }

    private function extractManyRelation(mixed $object, string $relationName, array $details): array
    {
        $result = [];
        $type = array_key_first($details) ?? 'list';
        $valueKey = $details[$type] ?? [];

        foreach ($object->toArray() as $parent) {
            $id = $parent['id'];
            $items = $parent[$relationName] ?? [];

            if ($type === 'count') {
                $result[$id] = ['count' => count($items)];
                continue;
            }

            if ($type === 'list') {
                $result[$id] = ['list' => collect($items)->pluck($valueKey)->toArray()];
                continue;
            }

            if ($type === 'concat') {
                $result[$id] = ['concat' => collect($items)->pluck($valueKey)->implode(' , ')];
                continue;
            }
        }

        return $result;
    }

    private function extractNestedRelation(mixed $relatedObject, ?string $nestedRelation, ?array $nestedDetails): array
    {
        $result = [];

        if ($nestedRelation && $nestedDetails) {
            $nestedResult = collect($nestedDetails)
                ->mapWithKeys(function ($type, $column) use ($relatedObject, $nestedRelation) {
                    $key = $nestedRelation . '_' . $column;
                    return [$key => $this->convertValue((object)$relatedObject?->$nestedRelation, $column, $type)];
                })
                ->toArray();

            $result[] = $nestedResult;
        }

        return $result;
    }

    private function resolveRelationKeys(array $relations): array
    {
        $results = [];

        foreach ($relations as $relation => $details) {
            $key = array_key_first($details);
            $results[$relation] = [
                'type' => $key,
                'name' => $details[$key] ?? null,
            ];
        }

        return $results;
    }

    private function guessRelationPattern(string $relation, array $columns, ?string $parent): array|string
    {
        $match = collect($columns)->filter(function ($v, $k) use ($relation) {
            return Str::contains($k, '.') && Str::before($k, '.') === $relation;
        })->toArray();

        $columns = [];
        foreach ($match as $key => $value) {
            $columns[Str::after($key, '.')] = $value;
        }

        return $this->nestedPattern($columns, $parent);
    }

    private function nestedPattern(?array $nested, ?string $parent): array
    {
        $result = [];
        foreach ($nested as $column => $type) {
            $result[$parent ? $parent . '_' . $column : $column] = $type;
        }

        return $result;
    }

    private function applyColumnFilter(array $nativeArray, string $type): array
    {
        $filteredColumns = $this->columns;

        // Allow explicit column selection via filter['columns']
        if (!empty($this->filter['columns']) && is_array($this->filter['columns'])) {
            $filteredColumns = Arr::only($filteredColumns, $this->filter['columns']);
        }

        if ($type !== 'all') {
            $filteredColumns = Arr::where($filteredColumns, function ($v, $k) use ($type) {
                return Str::contains($k, '.') || Str::contains($k, $type);
            });
        }

        return collect($nativeArray)->map(function ($object) use ($filteredColumns) {
            $filteredData = [];

            foreach ($filteredColumns as $column => $type) {
                $value = $object;
                if (Str::contains($column, '.')) {
                    $paths = explode('.', $column);
                    foreach ($paths as $path) {
                        $value = $value[$path] ?? null;
                    }
                } else {
                    $value = $object[$column] ?? null;
                }
                $filteredData[$column] = $value;
            }

            return $filteredData;
        })->toArray();
    }

    private function applyConditionFilter($query): void
    {
        foreach ($this->filter['conditions'] as $condition) {
            $query->where($condition['key'], $condition['operation'], $condition['value']);
        }
    }
}
