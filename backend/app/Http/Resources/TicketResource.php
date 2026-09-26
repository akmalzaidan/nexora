<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category
                ? [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'code' => $this->category->code,
                ]
                : null),
            'requester' => $this->whenLoaded('requester', fn (): ?UserResource => $this->requester
                ? new UserResource($this->requester)
                : null),
            'assignee' => $this->whenLoaded('assignee', fn (): ?UserResource => $this->assignee
                ? new UserResource($this->assignee)
                : null),
            'department' => $this->whenLoaded('department', fn (): ?array => $this->department
                ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                    'code' => $this->department->code,
                ]
                : null),
            'location' => $this->whenLoaded('location', fn (): ?array => $this->location
                ? [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'code' => $this->location->code,
                ]
                : null),
            'priority' => $this->priority,
            'status' => $this->status,
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
