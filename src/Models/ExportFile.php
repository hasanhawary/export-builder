<?php

namespace HasanHawary\ExportBuilder\Models;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\Models\Relations\ExportableMorphTo;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportFile extends Model
{
    use SoftDeletes;

    protected $table = 'export_files';

    protected $fillable = [
        'exportable_id',
        'exportable_type',
        'created_by',
        'file_name',
        'file_path',
        'disk',
        'format',
        'status',
        'started_at',
        'completed_at',
        'metadata',
        'error_message',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * The exported record, when exportable_id is set (null otherwise).
     * exportable_type may be a page slug ("user" → the model of UserExport),
     * a morph-map alias or a model class name.
     */
    public function exportable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @var array<string, string|null> */
    private static array $exportableClasses = [];

    /**
     * Model class for an exportable_type value, or null when it cannot be resolved.
     */
    public static function resolveExportableClass(string $type): ?string
    {
        if ($type === '') {
            return null;
        }

        $cacheKey = config('export.namespace') . '|' . $type;

        if (array_key_exists($cacheKey, self::$exportableClasses)) {
            return self::$exportableClasses[$cacheKey];
        }

        $class = Relation::getMorphedModel($type);

        if ($class === null && class_exists($type)) {
            $class = $type;
        }

        if ($class === null && ($exportClass = ExportBuilder::exportClassFor($type))) {
            try {
                $class = (new $exportClass([]))->exportModelClass();
            } catch (Throwable) {
                $class = null;
            }
        }

        $class = $class && is_subclass_of($class, Model::class) ? $class : null;

        return self::$exportableClasses[$cacheKey] = $class;
    }

    public static function getActualClassNameForMorph($class)
    {
        return self::resolveExportableClass((string) $class) ?? UnresolvedExportable::class;
    }

    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new ExportableMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    public function isReady(): bool
    {
        return $this->status === ExportStatus::Completed->value && ! empty($this->file_path);
    }

    public function url(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        try {
            return Storage::disk(app(ExportFileService::class)->diskFor($this))->url($this->file_path);
        } catch (Throwable) {
            // Some disks (ftp, sftp, private local disks) cannot build URLs.
            // A missing link must not break the whole export history listing.
            return null;
        }
    }
}
