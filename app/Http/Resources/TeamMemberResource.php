<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'user_id'   => $this->user_id,
            'user'      => new UserResource($this->whenLoaded('user')),
            'priority'  => $this->priority,
            'status'    => $this->status,       // current | past
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
