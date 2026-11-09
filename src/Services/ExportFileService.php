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

    /**
     * Move the export to the trash. The file is kept so the export can be restored.
     */
    public function delete(ExportFile $export): bool
    {
        return (bool) $export->delete();
    }

    /**
     * Bring a trashed export back, together with its file.
     */
    public function restore(ExportFile $export): bool
    {
        return (bool) $export->restore();
    }

    /**
     * Delete the export permanently: the stored file and the record.
     */
    public function forceDelete(ExportFile $export): bool
    {
        $disk = $this->diskFor($export);

        if ($export->file_path && Storage::disk($disk)->exists($export->file_path)) {
            Storage::disk($disk)->delete($export->file_path);
        }

        return (bool) $export->forceDelete();
    }

    /**
     * Storage path for a finished export. The export id keeps two exports with the
     * same file name (same page, same minute) from overwriting each other.
     */
    public function pathFor(ExportFile $export, string $name): string
    {
        return $this->storagePath() . '/' . $export->getKey() . '_' . $name;
    }

    /**
     * The disk a record was stored on, falling back to the configured disk.
     */
    public function diskFor(ExportFile $export): string
    {
        return $export->disk ?: $this->storageDisk();
    }

    /**
     * The configured storage disk for export files, or the app's default filesystem disk.
     * Single source of truth — all callers use this instead of reading config directly.
     */
    public function storageDisk(): string
    {
        return (string) (config('export.module.storage.disk') ?: config('filesystems.default') ?: 'local');
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
