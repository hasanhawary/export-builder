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
            'exportable_type' => $this->exportable_type,
            'exportable_id' => $this->exportable_id,
            'format' => $this->format,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'file_url' => $this->url(),
            'disk' => $this->disk,
            'metadata' => $this->metadata ?? [],
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
