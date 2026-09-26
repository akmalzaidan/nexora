<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item
                ? [
                    'id' => $this->item->id,
                    'sku' => $this->item->sku,
                    'name' => $this->item->name,
                ]
                : null),
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse
                ? [
                    'id' => $this->warehouse->id,
                    'name' => $this->warehouse->name,
                    'code' => $this->warehouse->code,
                ]
                : null),
            'performer' => $this->whenLoaded('performer', fn (): ?array => $this->performer
                ? [
                    'id' => $this->performer->id,
                    'name' => $this->performer->name,
                    'email' => $this->performer->email,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
