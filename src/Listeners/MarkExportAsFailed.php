<?php

namespace HasanHawary\ExportBuilder\Listeners;

use HasanHawary\ExportBuilder\Enums\ExportStatus;
use HasanHawary\ExportBuilder\Jobs\ExportToFile;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

/**
 * When the queue gives up on an export job (timeout, worker killed, tries exhausted),
 * the job's own catch block never runs and the export would stay "processing" forever.
 * Mark it failed here instead.
 */
class MarkExportAsFailed
{
    public function handle(JobFailed $event): void
    {
        $exportId = $this->exportId($event);

        if ($exportId === null) {
            return;
        }

        $export = ExportFile::find($exportId);

        if (! $export || in_array($export->status, [ExportStatus::Completed->value, ExportStatus::Failed->value], true)) {
            return;
        }

        app(ExportFileService::class)->markAsFailed($export, $event->exception?->getMessage() ?: 'Export job failed.');
    }

    private function exportId(JobFailed $event): ?int
    {
        try {
            $data = $event->job->payload()['data'] ?? [];

            if (! is_a((string) ($data['commandName'] ?? ''), ExportToFile::class, true)) {
                return null;
            }

            $command = @unserialize((string) ($data['command'] ?? ''), ['allowed_classes' => true]);

            return $command instanceof ExportToFile ? $command->exportId : null;
        } catch (Throwable) {
            // Encrypted or unreadable payload: leave the record as it is.
            return null;
        }
    }
}
