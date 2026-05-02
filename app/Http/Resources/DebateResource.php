<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'tag'              => $this->tag,
            'status'           => $this->status,
            'livekit_room_name'=> $this->livekit_room_name,
            'format'           => new DebateFormatResource($this->whenLoaded('format')),
            'motion'           => $this->whenLoaded('motion', fn() => [
                'id'   => $this->motion->id,
                'text' => $this->motion->text,
            ]),
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'started_at'       => $this->started_at?->toIso8601String(),
            'ended_at'         => $this->ended_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
