<?php

namespace HasanHawary\ExportBuilder;

use HasanHawary\ExportBuilder\Concerns\AdvancedFilter;
use HasanHawary\ExportBuilder\Concerns\CustomRelationTrait;
use HasanHawary\ExportBuilder\Concerns\HasColumnFilter;
use HasanHawary\ExportBuilder\Concerns\HasExcelStyles;
use HasanHawary\ExportBuilder\Concerns\HasMorphRelations;
use HasanHawary\ExportBuilder\Concerns\HasPdfOutput;
use HasanHawary\ExportBuilder\Concerns\HasQueryBuilder;
use HasanHawary\ExportBuilder\Concerns\HasRelations;
use HasanHawary\ExportBuilder\Concerns\HelperTrait;
use HasanHawary\ExportBuilder\Contracts\BaseExportContract;
use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

abstract class BaseExport implements BaseExportContract, FromCollection, WithMapping, WithHeadings, WithStyles, ShouldAutoSize
{
    use HelperTrait;
    use AdvancedFilter;
    use CustomRelationTrait;
    use HasRelations;
    use HasMorphRelations;
    use HasColumnFilter;
    use HasExcelStyles;
    use HasPdfOutput;
    use HasQueryBuilder;

    // -------------------------------------------------------------------------
    // Config properties (populated by the constructor)
    // -------------------------------------------------------------------------

    /** @var array<string, string>  column => type map */
    private array $columns;

    /**
     * Relation config:
     *   'one'   => [...],
     *   'many'  => ['concat' => [...], 'list' => [...], 'count' => [...]],
     *   'morph' => [...],
     */
    private array $relations;

    /** Closures or withAggregate calls applied to the base query */
    private array $additionalQuery;

    /** Eloquent model class string */
    private string|array $model;

    /** Column used for date-range filtering */
    private string $dateColumn;

    /** Raw filter array from the request */
    private array $filter;

    /** Extra with() paths passed in config */
    private array $customWith;

    /** Extra select() columns passed in config */
    private array $customSelect;

    /** Relation map used by AdvancedFilter for whereHas filtering */
    protected array $filterRelations;

    /** Cache for detectForeignKeys() — computed once per instance */
    private ?array $detectedForeignKeys = null;

    /** Cache for buildQuery() — assembled once, reused by collection() and pdfData() */
    private ?Builder $builtQuery = null;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /**
     * @param  array  $config  {
     *     model:           class-string,
     *     columns:         array<string, string>,
     *     relations?:      array,
     *     additionalQuery?: array,
     *     customWith?:     array,
     *     customSelect?:   array,
     *     dateColumn?:     string,
     *     date_column?:    string,
     *     filterRelations?: array,
     *     filter_relations?: array,
     * }
     * @param  array  $filter  Validated request data
     */
    public function __construct(array $config, array $filter)
    {
        $this->model  = $config['model'];
        $this->filter = $filter;

        $this->columns = $config['columns'];

        // Merge user-supplied relations over safe defaults so omitted keys never
        // cause "undefined index" errors in map() / headings() / buildQuery().
        $this->relations = array_replace_recursive([
            'one'  => [],
            'many' => ['concat' => [], 'list' => [], 'count' => []],
            'morph' => [],
        ], $config['relations'] ?? []);

        $this->additionalQuery  = $config['additionalQuery']  ?? [];
        $this->customWith       = $config['customWith']       ?? [];
        $this->customSelect     = $config['customSelect']     ?? [];
        $this->dateColumn       = $config['dateColumn']       ?? $config['date_column']       ?? 'created_at';
        $this->filterRelations  = $config['filterRelations']  ?? $config['filter_relations']  ?? [];
    }

    // -------------------------------------------------------------------------
    // Excel: map()  — one row per model instance
    // -------------------------------------------------------------------------

    public function map($object): array
    {
        $result = [];

        // Base columns — apply filter so map() and headings() are consistent
        $columns = $this->applyColumnFilter($this->columns);
        foreach ($columns as $column => $type) {
            $result[$column] = $this->convertValue($object, $column, $type);
        }

        // One-to-one relations
        $oneRelations = $this->applyColumnFilter($this->relations['one']);
        foreach ($oneRelations as $relation) {
            $result[key($relation)] = $this->extractOneRelationValue($object, $relation);
        }

        // Morph relations (no column filter — morph keys are always explicit)
        foreach ($this->relations['morph'] as $foreignKey => $morphConfig) {
            $result[$foreignKey] = $this->extractMorphRelation($object, $foreignKey, $morphConfig);
        }

        // One-to-many concat
        foreach ($this->applyColumnFilter($this->relations['many']['concat']) as $relation => $details) {
            $result[$relation] = $this->extractConcatRelationValue($object, $relation, $details);
        }

        // One-to-many list (formatted multi-line block)
        foreach ($this->applyColumnFilter($this->relations['many']['list'], 'many') as $relationName => $details) {
            $flat = $this->flattenArray($this->extractManyRelation($object, $relationName, $details));

            $result[$relationName] = collect($flat)
                ->reject(fn ($v, $k) => str_ends_with($k, '_id') || \is_array($v))
                ->map(fn ($v, $k) => ExportHelper::resolveTrans($k) . ': ' . (\is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR) : $v) . PHP_EOL)
                ->implode(str_repeat('-', 20) . PHP_EOL);
        }

        // Count relations
        foreach ($this->applyColumnFilter($this->relations['many']['count'], 'many') as $relationName) {
            [$relation, $alias] = str_contains($relationName, ' as ')
                ? explode(' as ', $relationName)
                : [$relationName, Str::snake($relationName) . '_count'];

            $result[$alias] = $object->{$alias};
        }

        // Additional query columns
        $additional = $this->applyColumnFilter($this->additionalQuery, 'many');
        foreach ($additional as $key => $value) {
            $result[$key] = $object->$key ?? '';
        }

        // Custom relation override (CustomRelationTrait)
        if (! empty($this->customRelations())) {
            $result = collect($result)
                ->reject(fn ($v, $k) => str_ends_with($k, '_id'))
                ->all();

            return array_merge($result, $this->mapRelations($object));
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Excel: headings()  — column header row
    // -------------------------------------------------------------------------

    public function headings(): array
    {
        // Work on local copies — never mutate instance state.
        // This makes headings() and map() order-independent and fixes the PDF
        // + column-filter bug where buildQuery() ran after $this->columns was destroyed.
        $columns        = $this->applyColumnFilter($this->columns);
        $oneRelations   = $this->applyColumnFilter($this->relations['one']);
        $concatRelations = $this->applyColumnFilter($this->relations['many']['concat']);
        $listRelations  = $this->applyColumnFilter($this->relations['many']['list'], 'many');
        $countRelations = $this->applyColumnFilter($this->relations['many']['count'], 'many');
        $additional     = $this->applyColumnFilter($this->additionalQuery, 'many');

        $keys = array_merge(
            array_keys($columns),
            array_keys($this->mergeKeyedArrays($oneRelations)),
            array_keys($this->relations['morph']),
            array_keys($concatRelations),
            array_keys($listRelations),
            array_map(
                fn ($name) => str_contains($name, ' as ') ? explode(' as ', $name)[1] : $name,
                $countRelations
            ),
            array_keys($additional)
        );

        $headings = Arr::map($keys, fn ($col) => $this->resolveTrans($col));

        if (! empty($this->customRelations())) {
            $headings = collect($headings)
                ->reject(fn ($v) => str_ends_with($v, '_id'))
                ->values()
                ->all();

            return array_merge($headings, $this->headingRelations());
        }

        return $headings;
    }

    // -------------------------------------------------------------------------
    // Misc
    // -------------------------------------------------------------------------

    /**
     * Return false in a subclass to disable the export entirely (results in 403).
     */
    public function isEnabled(): bool
    {
        return true;
    }
}
