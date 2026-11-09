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
use RuntimeException;
use Throwable;

class ExportToFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $exportId)
    {
        $connection = config('export.module.queue.connection');
        $queue      = config('export.module.queue.name');

        if ($connection) {
            $this->onConnection($connection);
        }

        if ($queue) {
            $this->onQueue($queue);
        }
    }

    public function handle(ExportFileService $service): void
    {
        $export = ExportFile::find($this->exportId);

        if (! $export) {
            return;
        }

        $sourcePath = null;

        try {
            $service->markAsProcessing($export);

            $filters  = $export->metadata['filters'] ?? [];
            $response = (new ExportBuilder($filters))->response();

            $sourcePath = $response->getFile()->getRealPath();
            $name       = ExportBuilder::buildFileName($filters, $export->format);
            $path       = $service->pathFor($export, $name);

            // Stream the file to the disk instead of loading it into memory.
            $stream = fopen($sourcePath, 'rb');

            try {
                if (! Storage::disk($service->diskFor($export))->put($path, $stream)) {
                    throw new RuntimeException("Could not write export file to [{$path}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $service->markAsCompleted($export, $path, $name);
        } catch (Throwable $e) {
            $service->markAsFailed($export, $e->getMessage());
            throw $e;
        } finally {
            // The generated file is never sent as a response here, so remove it ourselves.
            if ($sourcePath && is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }
}
