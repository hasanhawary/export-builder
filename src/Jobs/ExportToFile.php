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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExportToFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Seconds before the worker kills the job (null → worker default). Untyped so subclasses can override. */
    public $timeout = null;

    /** Attempts before the job fails (null → worker default). */
    public $tries = null;

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

        $this->timeout ??= $this->positiveInt(config('export.module.queue.timeout'));
        $this->tries   ??= $this->positiveInt(config('export.module.queue.tries'));
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function handle(ExportFileService $service): void
    {
        $export = ExportFile::find($this->exportId);

        if (! $export) {
            return;
        }

        $sourcePath = null;
        $actingAs   = $this->actAsRequester($export);

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
            Log::error('ExportBuilder: queued export failed', ['export_id' => $export->id, 'exception' => $e]);
            throw $e;
        } finally {
            // The generated file is never sent as a response here, so remove it ourselves.
            if ($sourcePath && is_file($sourcePath)) {
                @unlink($sourcePath);
            }

            if ($actingAs) {
                Auth::forgetUser();
            }
        }
    }

    /**
     * Build the file as the user who asked for it, so export classes that scope rows by
     * auth()->user() work the same in the worker as in the request. Skipped when a user is
     * already set (e.g. by a subclass) or when 'export.module.queue.run_as_requester' is false.
     */
    private function actAsRequester(ExportFile $export): bool
    {
        if (! config('export.module.queue.run_as_requester', true) || ! $export->created_by) {
            return false;
        }

        try {
            $guard = Auth::guard();

            if ($guard->check() || ! method_exists($guard, 'getProvider') || ! $guard->getProvider()) {
                return false;
            }

            $user = $guard->getProvider()->retrieveById($export->created_by);

            if (! $user) {
                return false;
            }

            $guard->setUser($user);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
