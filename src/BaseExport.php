<?php

namespace HasanHawary\ExportBuilder;

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
    private array $customSelect;


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
        $this->relations = $config['relations'];

        /**
         * Custom With (Eager Loading) Configuration:
         *
         * Define additional relationships to an eager load on the base query.
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
         * Custom Select Configuration:
         *
         * Specifies the columns to select on the base query.
         * Helps reduce data load and improve query performance
         * by avoiding selecting all columns.
         *
         * Must be an array compatible with Eloquent's select() method.
         *
         * Example:
         * ['id', 'name', 'created_at']
         */
        $this->customSelect = $config['customSelect'] ?? [];


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
        $columns = array_merge(array_keys($this->columns), array_keys($this->relations['one']));
        $relations = $this->resolveRelationKeys($this->mergeKeyedArrays($this->relations['one']));
        $relations = array_merge($relations, $this->resolveRelationKeys($this->applyColumnFilter($this->relations['many']['concat'])));
        $relations = array_merge($relations, $this->resolveRelationKeys($this->relations['many']['list']));
        $relations = array_merge($relations, $this->customWith);
        $countRelations = $this->relations['many']['count'];
        $data = [];

        $query = $this->model::select(array_merge($columns, $this->customSelect));

        // Handle With methods based on give relations
        if (!empty($countRelations)) {
            $query->withCount(...$countRelations);
        }

        if (!empty($relations)) {
            $query->with(...Arr::flatten($relations));
        }

        // Handle Additional Query like closure functions
        if (!empty($this->additionalQuery)) {
            collect($this->additionalQuery)->each(fn($item) => $item($query));
        }

        // Handle conditions filters
        $this->appleDateFilter($query);
        $this->applyAdvancedFilter($query);

        //Fetch data with chunk to handle big data
        $query->chunk(100, function ($dataChunk) use (&$data) {
            $dataChunk->each(function ($item) use (&$data) {
                $data[] = $item;
            });
        });

        return $data;
    }

    public function map($object): array
    {
        $result = [];

        // Handle columns
        foreach ($this->columns as $column => $type) {
            $result[$column] = $this->convertValue($object, $column, $type);
        }

        // Handle one-to-one relations
        foreach ($this->relations['one'] as $relation) {
            $oneRelation = $this->extractOneRelation($object, $relation);

            $result[key($relation)] = current(Arr::where(Arr::dot($oneRelation), function ($value, $key) {
                $strPart = Str::of($key)->explode('.');
                return ($strPart->last() === 'id' && $strPart->count() === 1) || $strPart->last() !== 'id';
            }));
        }

        // Handle one-to-many relations (concat)
        foreach ($this->applyColumnFilter($this->relations['many']['concat']) as $relation => $details) {
            $manyRelation = $this->extractManyRelation($object, $relation, $details);
            $result[$relation] = implode(', ', Arr::where(Arr::dot($manyRelation), function ($value, $key) {
                $strPart = Str::of($key)->explode('.');
                return ($strPart->last() === 'id' && $strPart->count() === 1) || $strPart->last() !== 'id';
            }));
        }

        //Handle one-to-many relations (many column)
        foreach ($this->relations['many']['list'] as $relationName => $details) {
            $flattenedData = $this->flattenArray($this->extractManyRelation($object, $relationName, $details));
            $finalResult = collect($flattenedData)->reject(function ($value, $key) {
                return str_ends_with($key, '_id') || is_array($value);
            })->map(function ($value, $key) {
                return resolveTrans($key) . ": " . (is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value) . PHP_EOL;
            })->implode(str_repeat('-', 20) . PHP_EOL);

            $result[$relationName] = $finalResult;
        }

        //Handle one-to-many relations (count)
        foreach ($this->relations['many']['count'] as $relationName) {
            $result[$relationName] = $object->{Str::snake($relationName) . '_count'};
        }

        //Handle additional relations
        foreach ($this->additionalQuery as $key => $value) {
            $result[$key] = $object->$key ?? '';
        }

        //Resolve for last shape && handle column key
        return $result;
    }

    public function headings(): array
    {
        $this->columns = $this->applyColumnFilter($this->columns);
        $this->relations['one'] = $this->applyColumnFilter($this->relations['one']);
        $this->relations['many']['concat'] = $this->applyColumnFilter($this->relations['many']['concat']);
        $this->relations['many']['list'] = $this->applyColumnFilter($this->relations['many']['list'], 'many');
        $this->relations['many']['count'] = $this->applyColumnFilter($this->relations['many']['count'], 'many');
        $this->additionalQuery = $this->applyColumnFilter($this->additionalQuery, 'many');

        $columnHeadings = array_keys($this->columns);
        $oneRelationHeadings = array_keys($this->mergeKeyedArrays($this->relations['one']));
        $concatRelationHeadings = array_keys(array_map(fn($relation) => array_keys($relation), $this->relations['many']['concat']));
        $manyRelationHeadings = array_keys(array_map(fn($relation) => array_keys($relation), $this->relations['many']['list']));
        $countRelationHeadings = $this->relations['many']['count'];
        $additionalHeading = array_keys($this->additionalQuery);

        $columns = array_merge($columnHeadings, $oneRelationHeadings, $concatRelationHeadings, $manyRelationHeadings, $countRelationHeadings, $additionalHeading);

        return Arr::map($columns, fn($item) => $this->resolveTrans($item));
    }

    public function isEnabled(): true
    {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Relations Methods
    |--------------------------------------------------------------------------
    */
    private function extractOneRelation(mixed $parentEntity, array $relationMappings): array
    {
        $extractedData = [];
        foreach ($relationMappings as $relationName => $columnMappings) {
            $relatedEntity = $parentEntity->$relationName;

            foreach ($columnMappings as $columnName => $columnType) {
                if (is_array($columnType)) {
                    $extractedData[$relationName][$columnName] = $this->extractOneRelation($relatedEntity, $columnType);
                } else {
                    $extractedData[$relationName][$columnName] = $this->convertValue($relatedEntity, $columnName, $columnType);
                }
            }
        }

        return $extractedData;
    }

    private function extractManyRelation(mixed $object, string $relationName, array $details): array
    {
        $relatedDataSet = [];
        foreach ($object->$relationName as $relatedObject) {
            $relatedData = [];
            foreach ($details as $relatedColumn => $type) {
                if (is_array($type)) {
                    foreach ($type as $nestedRelation => $nestedDetails) {
                        $relatedData[$nestedRelation] = Arr::first($this->extractNestedRelation($relatedObject, $nestedRelation, $nestedDetails));
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
        if (is_array($relatedObject->$nestedRelation) && count($relatedObject->$nestedRelation) > 0 && is_object($relatedObject->$nestedRelation[0])) {
            return $this->extractManyRelation($relatedObject, $nestedRelation, $nestedDetails);
        }

        return $this->extractOneRelation($relatedObject, [$nestedRelation => $nestedDetails]);
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Display of Relations Methods
    |--------------------------------------------------------------------------
    */
    private function resolveRelationKeys(array $relations): array
    {
        $selectedRelations = [];
        collect($relations)->each(function ($relationValue, $relationName) use (&$selectedRelations) {
            $selectedRelations[$relationName] = $this->guessRelationPattern($relationName, $relationValue);
        });

        return array_values($selectedRelations);
    }

    private function guessRelationPattern(string $relation, array $columns, ?string $parent = null): array|string
    {
        $nested = [];
        $normalRelation = collect($columns)->filter(fn($r) => !is_array($r))->toArray();
        $nestedRelations = collect($columns)->filter(fn($r) => is_array($r));
        $patternColumns = implode(',', array_keys($normalRelation));

        if ($nestedRelations->isNotEmpty()) {
            $nestedRelations->each(function ($nestedRelation) use (&$nested, $relation) {
                collect($nestedRelation)->each(function ($relationValue, $relationName) use (&$nested, $relation) {
                    $nested[] = $this->guessRelationPattern($relationName, $relationValue, $relation);
                });
            });
        }

        $basicRelation = "$relation:$patternColumns";
        if ($parent) {
            $nested = !empty($nested) ? $this->nestedPattern($nested, $parent) : "$relation:$patternColumns";

            if (!is_array($nested) && $basicRelation === $nested) {
                $basicRelation = [];
                $nested = "$parent.$nested";
            } else {
                $basicRelation = "$parent.$basicRelation";
            }
        }

        if (is_string($nested)) {
            $basicRelation = $nested;
        }

        return is_array($nested) && !empty($nested) ? Arr::flatten($nested) : $basicRelation;
    }

    private function nestedPattern(?array $nested = [], ?string $parent = null): array
    {
        return $parent && !empty($nested) ? array_map(function ($item) use ($parent) {
            return is_array($item) ? $this->nestedPattern($item, $parent) : "$parent.$item";
        }, $nested) : $nested;
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Methods
    |--------------------------------------------------------------------------
    */
    private function applyColumnFilter(array $nativeArray, string $type = 'column'): array
    {
        if (empty($this->filter['column'])) {
            return $nativeArray;
        }

        if (!isArrayIndex($nativeArray)) {
            if ($type === 'many') {
                return Arr::only($nativeArray, $this->filter['related'] ?? []);
            }

            return Arr::only($nativeArray, $this->filter['columns'] ?? []);
        }

        //write condition here
        if ($type === 'many') {
            foreach ($nativeArray as $key => $value) {
                if (!in_array($value, $this->filter['related'] ?? [], true)) {
                    unset($nativeArray[$key]);
                }
            }
        }

        return $nativeArray;
    }

    private function applyAdvancedFilter($query): void
    {
        if (isset($this->filter['advanced'])) {
            collect($this->filter['advanced'])->each(function ($item) use ($query) {
                if (array_key_exists($item->key, $this->relations['many']['concat'])) {
                    $query->whereHas($item->key, fn($q) => $q->whereIn('id', Arr::wrap($item->value)));
                } else {
                    $query->whereIn($item->key, Arr::wrap($item->value));
                }
            });
        }
    }

    protected function appleDateFilter($query): void
    {
        if (isset($this->filter['start'])) {
            $query->whereDate($this->dateColumn, '>=', $this->filter['start']);
        }

        if (isset($this->filter['end'])) {
            $query->whereDate($this->dateColumn, '<=', $this->filter['end']);
        }
    }
}
