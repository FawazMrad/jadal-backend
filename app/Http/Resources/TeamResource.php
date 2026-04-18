<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Separate current and past members when teamMembers is loaded
        $allMembers     = $this->whenLoaded('teamMembers');
        $currentMembers = null;

        if ($allMembers instanceof \Illuminate\Support\Collection) {
            $currentMembers = $allMembers
                ->where('status', 'current')
                ->sortBy('priority')
                ->values();
        }

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'status'          => $this->status,
            'is_random'       => $this->is_random,
            'leader'          => new UserResource($this->whenLoaded('leader')),
            'created_by'      => new UserResource($this->whenLoaded('createdBy')),
            'members_count'   => $currentMembers?->count() ?? $this->teamMembers?->where('status', 'current')->count(),
            'members'         => TeamMemberResource::collection($this->whenLoaded('teamMembers')),
            'current_members' => $currentMembers
                ? TeamMemberResource::collection($currentMembers)
                : null,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
