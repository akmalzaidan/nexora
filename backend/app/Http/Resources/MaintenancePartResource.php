<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaintenancePartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item
                ? [
                    'id' => $this->item->id,
                    'sku' => $this->item->sku,
                    'name' => $this->item->name,
                    'unit' => $this->item->unit,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
