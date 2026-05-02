<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'debate_id'     => $this->debate_id,
            'judge'         => new UserResource($this->whenLoaded('judge')),
            'winning_side'  => $this->winning_side,
            'scores'        => $this->scores,
            'summary_notes' => $this->summary_notes,
            'submitted_at'  => $this->submitted_at?->toIso8601String(),
        ];
    }
}
