<?php

namespace HasanHawary\ExportBuilder\Services;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\Models\ExportFile;
use Illuminate\Support\Facades\Storage;

class ExportFileService
{
    public function create(array $filters): ExportFile
    {
        return ExportFile::create([
            'exportable_type' => $filters['page'],
            'format'          => $filters['format'] ?? 'xlsx',
            'status'          => ExportStatus::Pending->value,
            'disk'            => $this->storageDisk(),
            'metadata'        => ['filters' => $filters],
            'created_by'      => auth()->id(),
        ]);
    }

    public function markAsProcessing(ExportFile $export): void
    {
        $export->update([
            'status'     => ExportStatus::Processing->value,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(ExportFile $export, string $path, string $name): void
    {
        $export->update([
            'status'        => ExportStatus::Completed->value,
            'file_path'     => $path,
            'file_name'     => $name,
            'completed_at'  => now(),
            'error_message' => null,
        ]);
    }

    public function markAsFailed(ExportFile $export, string $message): void
    {
        $export->update([
            'status'        => ExportStatus::Failed->value,
            'error_message' => $message,
            'completed_at'  => now(),
        ]);
    }

    public function delete(ExportFile $export): bool
    {
        $disk = $export->disk ?: $this->storageDisk();

        if ($export->file_path && Storage::disk($disk)->exists($export->file_path)) {
            Storage::disk($disk)->delete($export->file_path);
        }

        return (bool) $export->delete();
    }

    /**
     * The configured storage disk for export files.
     * Single source of truth — all callers use this instead of reading config directly.
     */
    public function storageDisk(): string
    {
        return (string) (config('export.module.storage.disk') ?: 'local');
    }

    /**
     * The configured base storage path for export files.
     * Single source of truth — all callers use this instead of reading config directly.
     */
    public function storagePath(): string
    {
        return trim((string) (config('export.module.storage.path') ?: 'exports'), '/');
    }
}
