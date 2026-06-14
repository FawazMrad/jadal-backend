<?php

namespace App\Http\Resources;

use App\Models\DebateParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $data = [
            'id'               => $this->id,
            'title'            => $this->title,
            'tag'              => $this->tag,
            'status'           => $this->status,
            'cancellation_reason' => $this->cancellation_reason,
            'livekit_room_name'=> $this->livekit_room_name,
            'format'           => new DebateFormatResource($this->whenLoaded('format')),
            'motion'           => $this->motionVisible($user)
                ? new MotionResource($this->whenLoaded('motion'))
                : null,
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'started_at'       => $this->started_at?->toIso8601String(),
            'ended_at'         => $this->ended_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];

        // Debaters get their own registration status on each debate.
        if ($user && $user->role === 'debater') {
            $data['my_participation_status'] = $this->myParticipationStatus($user->id);
        }

        return $data;
    }

    /**
     * The motion is public once it has been revealed, once the debate is live or
     * completed (it was necessarily revealed by then), or always to admins.
     */
    private function motionVisible($user): bool
    {
        if ($user && $user->role === 'admin') {
            return true;
        }

        if (in_array($this->status, ['live', 'completed'], true)) {
            return true;
        }

        return $this->motion_revealed_at !== null && $this->motion_revealed_at->isPast();
    }

    /**
     * One of: not_registered | pending | approved | rejected.
     * Prefers the constrained eager-loaded relation to avoid N+1; falls back to a
     * single lookup for callers that did not eager-load it.
     */
    private function myParticipationStatus(int $userId): string
    {
        if ($this->relationLoaded('participants')) {
            $participant = $this->participants->firstWhere('user_id', $userId);
        } else {
            $participant = DebateParticipant::where('debate_id', $this->id)
                ->where('user_id', $userId)
                ->first();
        }

        return $participant?->status ?? 'not_registered';
    }
}
