<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamJoinRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'team_id'      => $this->team_id,
            'user'         => new UserResource($this->whenLoaded('user')),
            'status'       => $this->status,
            'reason'       => $this->reason,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'requested_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
