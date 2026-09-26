<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'notes' => $this->notes,
            'user' => $this->whenLoaded('user', fn (): ?UserResource => $this->user
                ? new UserResource($this->user)
                : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
