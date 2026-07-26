<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Admin view of one "user has this achievement" assignment, incl. audit trail. */
class AchievementAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'achievement' => new AchievementCatalogResource($this->whenLoaded('achievement')),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'assigned_by' => new PublicUserResource($this->whenLoaded('assignedBy')),
        ];
    }
}
