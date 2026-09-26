<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'result' => $this->result,
            'cost' => $this->cost !== null ? (string) $this->cost : null,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'request' => $this->whenLoaded('request', fn (): ?array => $this->request
                ? [
                    'id' => $this->request->id,
                    'title' => $this->request->title,
                    'status' => $this->request->status,
                ]
                : null),
            'asset' => $this->whenLoaded('asset', fn (): ?array => $this->asset
                ? [
                    'id' => $this->asset->id,
                    'asset_code' => $this->asset->asset_code,
                    'name' => $this->asset->name,
                    'status' => $this->asset->status,
                ]
                : null),
            'technician' => $this->whenLoaded('technician', fn (): ?UserResource => $this->technician
                ? new UserResource($this->technician)
                : null),
            'parts' => MaintenancePartResource::collection($this->whenLoaded('parts')),
            'parts_count' => $this->whenCounted('parts'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
