<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Administrative shape of a user as managed through /api/v1/users.
 *
 * Never exposes password, remember_token, or any personal access token.
 * Role and department are nested as flat objects only when loaded, so the
 * response can never recurse into a user's full relationships.
 */
class UserManagementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'role' => $this->whenLoaded('role', fn (): ?array => $this->role
                ? [
                    'id' => $this->role->id,
                    'name' => $this->role->name,
                    'slug' => $this->role->slug,
                ]
                : null),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department
                ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                    'code' => $this->department->code,
                ]
                : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
