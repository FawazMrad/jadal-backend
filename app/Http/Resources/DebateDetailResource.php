<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'description'       => $this->description,
            'tag'               => $this->tag,
            'status'            => $this->status,
            'livekit_room_name' => $this->livekit_room_name,
            'recording_url'     => $this->recording_url,
            'transcript'        => $this->transcript,
            'format'            => new DebateFormatResource($this->whenLoaded('format')),
            'motion'            => new MotionResource($this->whenLoaded('motion')),
            'created_by'        => new UserResource($this->whenLoaded('createdBy')),
            'participants'      => DebateParticipantResource::collection($this->whenLoaded('participants')),
            'phases'            => $this->whenLoaded('phases', fn() =>
                $this->phases->sortBy('order_index')->values()->map(fn($p) => [
                    'id'               => $p->id,
                    'name'             => $p->name,
                    'order_index'      => $p->order_index,
                    'duration_seconds' => $p->duration_seconds,
                    'status'           => $p->status,
                    'started_at'       => $p->started_at?->toIso8601String(),
                    'ended_at'         => $p->ended_at?->toIso8601String(),
                ])
            ),
            'result'            => new DebateResultResource($this->whenLoaded('result')),
            'feedbacks'         => FeedbackResource::collection($this->whenLoaded('feedbacks')),
            'scheduled_at'      => $this->scheduled_at?->toIso8601String(),
            'started_at'        => $this->started_at?->toIso8601String(),
            'ended_at'          => $this->ended_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
