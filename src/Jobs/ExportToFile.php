<?php

namespace HasanHawary\ExportBuilder\Jobs;

use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\Models\ExportFile;
use HasanHawary\ExportBuilder\Services\ExportFileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportToFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $exportId) {}

    public function handle(ExportFileService $service): void
    {
        $export = ExportFile::find($this->exportId);

        if (! $export) {
            return;
        }

        try {
            $service->markAsProcessing($export);

            $filters  = $export->metadata['filters'] ?? [];
            $response = (new ExportBuilder($filters))->response();

            $sourcePath = $response->getFile()->getRealPath();
            $name       = ExportBuilder::buildFileName($filters, $export->format);
            $path       = $service->storagePath() . '/' . $name;
            $disk       = $export->disk ?: $service->storageDisk();

            Storage::disk($disk)->put($path, file_get_contents($sourcePath));

            $service->markAsCompleted($export, $path, $name);
        } catch (Throwable $e) {
            $service->markAsFailed($export, $e->getMessage());
            throw $e;
        }
    }
}
