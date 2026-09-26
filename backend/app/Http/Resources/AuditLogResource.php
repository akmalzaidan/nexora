<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Governance shape of an audit log row (Phase 20A).
 *
 * Compact and fact-only: the actor is reduced to id + name (never email,
 * password, or tokens), the resource is identified by controlled vocabulary
 * type + id (never a PHP class name), and old/new values are returned exactly
 * as stored — they were already scrubbed by SensitiveValueFilter at write
 * time. A deleted actor renders as `actor: null` because the schema FK is
 * nullOnDelete; the audit row survives and says nothing false about the user.
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => $this->whenLoaded('user', fn (): ?array => $this->user
                ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ]
                : null),
            'action' => $this->action,
            'resource' => [
                'type' => $this->entity_type,
                'id' => $this->entity_id,
            ],
            'description' => $this->description,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
