<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    /**
     * When true, contact/personal fields on every embedded user (leader,
     * coach, members) are nulled while all keys and types stay identical.
     * Set for GET /teams/{id} callers who are not the coach, the leader or a
     * current member (§6).
     *
     * Defaults to false, so all pre-existing call sites are unaffected.
     */
    public function __construct($resource, private bool $stripPii = false)
    {
        parent::__construct($resource);
    }

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
            'leader'          => $this->whenLoaded(
                'leader',
                fn () => new UserResource($this->leader, $this->stripPii)
            ),
            'created_by'      => $this->whenLoaded(
                'createdBy',
                fn () => new UserResource($this->createdBy, $this->stripPii)
            ),
            'members_count'   => $currentMembers?->count() ?? $this->teamMembers?->where('status', 'current')->count(),
            'members'         => $this->whenLoaded(
                'teamMembers',
                fn () => $this->stripPii
                    ? TeamMemberResource::guestCollection($this->teamMembers)
                    : TeamMemberResource::collection($this->teamMembers)
            ),
            'current_members' => $currentMembers
                ? ($this->stripPii
                    ? TeamMemberResource::guestCollection($currentMembers)
                    : TeamMemberResource::collection($currentMembers))
                : null,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
