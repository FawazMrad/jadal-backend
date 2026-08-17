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
            // GET /motions is readable by ANY authenticated user, so embedding a
            // full UserResource here published the motion author's email, phone,
            // birth date and location to every debater on the platform. Only the
            // name and avatar are needed to render "added by X"; the remaining
            // keys stay present (nulled) so no client parser changes.
            'added_by'   => $this->whenLoaded(
                'addedBy',
                fn () => new UserResource($this->addedBy, stripPii: true)
            ),
            'frameworks' => MotionFrameworkResource::collection($this->whenLoaded('frameworks')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
