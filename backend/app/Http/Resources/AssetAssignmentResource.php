<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset' => $this->whenLoaded('asset', fn (): ?array => $this->asset
                ? [
                    'id' => $this->asset->id,
                    'asset_code' => $this->asset->asset_code,
                    'name' => $this->asset->name,
                ]
                : null),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user
                ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ]
                : null),
            'requested_by' => $this->whenLoaded('requester', fn (): ?array => $this->requester
                ? [
                    'id' => $this->requester->id,
                    'name' => $this->requester->name,
                ]
                : null),
            'location' => $this->whenLoaded('location', fn (): ?array => $this->location
                ? [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'code' => $this->location->code,
                ]
                : null),
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'returned_at' => $this->returned_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
