<?php

namespace HasanHawary\ExportBuilder\Models;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

        try {
            return Storage::disk(app(ExportFileService::class)->diskFor($this))->url($this->file_path);
        } catch (Throwable) {
            // Some disks (ftp, sftp, private local disks) cannot build URLs.
            // A missing link must not break the whole export history listing.
            return null;
        }
    }
}
