<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AchievementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => (int) $this->id,
            'user_id'    => (int) $this->user_id,
            'name'       => $this->name,
            'rank'       => $this->rank,
            // Nullable — FE substitutes its own default asset when null.
            'image_url'  => $this->image_url
                ? (str_starts_with($this->image_url, 'http')
                    ? $this->image_url
                    : Storage::disk('public')->url($this->image_url))
                : null,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
        ];
    }
}
