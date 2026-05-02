<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'description'       => $this->description,
            'target_roles'      => $this->target_roles,
            'closes_at'         => $this->closes_at?->toIso8601String(),
            'is_closed'         => $this->closes_at !== null && $this->closes_at->isPast(),
            'created_by'        => new UserResource($this->whenLoaded('createdBy')),
            'already_responded' => (bool) ($this->already_responded ?? false),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
