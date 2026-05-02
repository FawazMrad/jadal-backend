<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'debate_id'  => $this->debate_id,
            'from_user'  => new UserResource($this->whenLoaded('fromUser')),
            'to_user'    => new UserResource($this->whenLoaded('toUser')),
            'type'       => $this->type,
            'content'    => $this->content,
            'scores'     => $this->scores,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
