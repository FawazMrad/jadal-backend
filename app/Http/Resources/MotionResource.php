<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'text'       => $this->text,
            'added_by'   => new UserResource($this->whenLoaded('addedBy')),
            'frameworks' => MotionFrameworkResource::collection($this->whenLoaded('frameworks')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
