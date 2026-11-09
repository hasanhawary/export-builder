<?php

namespace HasanHawary\ExportBuilder\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExportFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exportable_type' => eb_resolveTrans($this->exportable_type),
            'exportable_id' => $this->exportable_id,
            'format' => $this->format,
            'status' => $this->status,
            'display_status' => eb_resolveTrans($this->status),
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_url' => $this->url(),
            'disk' => $this->disk,
            'metadata' => $this->metadata ?? [],
            'error_message' => $this->publicErrorMessage(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * The raw error (SQL, paths, class names) stays in the database and the log.
     * Users get a translated message unless debug or 'expose_errors' is on.
     */
    protected function publicErrorMessage(): ?string
    {
        if (! $this->error_message) {
            return null;
        }

        $expose = config('export.module.expose_errors');

        if ($expose ?? config('app.debug')) {
            return $this->error_message;
        }

        return eb_resolveTrans('export_failed_message');
    }
}
