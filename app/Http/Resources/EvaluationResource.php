<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'trainer'    => new UserResource($this->whenLoaded('trainer')),
            'debater'    => new UserResource($this->whenLoaded('debater')),
            'debate_id'  => $this->debate_id,
            'notes'      => $this->notes,
            'scores'     => $this->scores,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
