<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurveyDetailResource extends JsonResource
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
            'questions'         => $this->whenLoaded('questions', fn() =>
                $this->questions->sortBy('order_index')->values()->map(fn($q) => [
                    'id'            => $q->id,
                    'question_text' => $q->question_text,
                    'type'          => $q->type,
                    'options'       => $q->options,
                    'order_index'   => $q->order_index,
                ])
            ),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
