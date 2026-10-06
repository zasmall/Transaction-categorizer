<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount->name),
            'profile' => $this->whenLoaded('importProfile', fn () => $this->importProfile->name),
            'status' => $this->status,
            'is_finished' => $this->status->isFinished(),
            'total_rows' => $this->total_rows,
            'imported_rows' => $this->imported_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'failed_rows' => $this->failed_rows,
            'categorized_rows' => $this->categorized_rows,
            'error' => $this->error,
            'uploaded_by' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
