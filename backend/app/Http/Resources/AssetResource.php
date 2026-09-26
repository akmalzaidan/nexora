<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_code' => $this->asset_code,
            'name' => $this->name,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'status' => $this->status,
            'condition' => $this->condition,
            'purchase_date' => $this->purchase_date?->toISOString(),
            'purchase_price' => $this->purchase_price !== null ? (float) $this->purchase_price : null,
            'warranty_expiry' => $this->warranty_expiry?->toISOString(),
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category
                ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'code' => $this->category->code,
                ]
                : null),
            'location' => $this->whenLoaded('location', fn (): ?array => $this->location
                ? [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'code' => $this->location->code,
                ]
                : null),
            'current_user' => $this->whenLoaded('currentUser', fn (): ?array => $this->currentUser
                ? [
                    'id' => $this->currentUser->id,
                    'name' => $this->currentUser->name,
                    'email' => $this->currentUser->email,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
