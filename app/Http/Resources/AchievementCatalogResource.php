<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Admin catalog view of an Achievement — the definition, not a user's earning of it. */
class AchievementCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'type'       => $this->type,
            'image_url'  => $this->image_url
                ? (str_starts_with($this->image_url, 'http')
                    ? $this->image_url
                    : Storage::disk('public')->url($this->image_url))
                : null,
            // Present only where the controller preloaded it (withCount/loadCount) —
            // lets the dashboard show "assigned to N users" without a second call.
            'assigned_count' => $this->whenCounted('assignments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
