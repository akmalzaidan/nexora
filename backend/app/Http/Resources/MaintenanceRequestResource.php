<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'asset' => $this->whenLoaded('asset', fn (): ?array => $this->asset
                ? [
                    'id' => $this->asset->id,
                    'asset_code' => $this->asset->asset_code,
                    'name' => $this->asset->name,
                    'status' => $this->asset->status,
                ]
                : null),
            'requester' => $this->whenLoaded('requester', fn (): ?UserResource => $this->requester
                ? new UserResource($this->requester)
                : null),
            'assignee' => $this->whenLoaded('assignee', fn (): ?UserResource => $this->assignee
                ? new UserResource($this->assignee)
                : null),
            'records' => MaintenanceRecordResource::collection($this->whenLoaded('records')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
