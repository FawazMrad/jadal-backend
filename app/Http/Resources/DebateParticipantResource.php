<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'user'                 => new UserResource($this->whenLoaded('user')),
            'team_id'              => $this->team_id,
            // null when team_id is null (no team) — matches team_id 1:1 since
            // this is a straight belongsTo lookup, not a separate condition.
            'team_name'            => $this->whenLoaded('team', fn () => $this->team?->name),
            'role'                 => $this->role,
            'side'                 => $this->side,
            'status'               => $this->status,
            'is_chair'             => (bool) $this->is_chair,
            'is_attended'          => (bool) $this->is_attended,
            'speaking_phase_order' => $this->speaking_phase_order,
            // The chosen reply speaker (reply-format debates) — lets the client
            // label "PR/OR" and pre-select the reply speaker in the order dialog.
            'is_reply_speaker'     => (bool) $this->is_reply_speaker,
        ];
    }
}
