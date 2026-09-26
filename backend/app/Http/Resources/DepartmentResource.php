<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a department. The manager is always a minimal user
 * representation (id/name/email) so a nested department never cycles back into
 * users. users_count is only present when the query loaded it (withCount).
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'manager' => $this->whenLoaded('manager', fn (): ?array => $this->manager
                ? [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name,
                    'email' => $this->manager->email,
                ]
                : null),
            'users_count' => $this->whenHas('users_count', fn (): int => (int) $this->users_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
