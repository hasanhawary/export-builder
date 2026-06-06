<?php

namespace HasanHawary\ExportBuilder\Models;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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

    public function exportable(): MorphTo
    {
        return $this->morphTo();
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

        // Resolve disk from the record itself; fall back to the service default via config.
        // The model intentionally avoids injecting ExportFileService to stay framework-lightweight.
        $disk = $this->disk ?: (string) config('export.module.storage.disk', 'local');

        return Storage::disk($disk)->url($this->file_path);
    }
}
