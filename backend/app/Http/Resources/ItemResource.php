<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'minimum_stock' => $this->minimum_stock,
            'maximum_stock' => $this->maximum_stock,
            'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category
                ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'code' => $this->category->code,
                ]
                : null),
            'stock' => [
                'total' => (int) ($this->stock_in_total ?? 0) - (int) ($this->stock_out_total ?? 0),
                'warehouses' => $this->when(isset($this->stock_warehouses), $this->stock_warehouses ?? []),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
