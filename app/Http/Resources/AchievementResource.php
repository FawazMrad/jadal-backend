<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Mobile/public-facing shape — UNCHANGED since the catalog+assignment
 * restructure: id, user_id, name, rank, image_url, awarded_at. Only the
 * source of each field moved (name/image_url now come from the catalog
 * Achievement; id/user_id/awarded_at now come from its pivot
 * AchievementAssignment row). `rank` keeps its old lowercase casing; the one
 * unavoidable value change is 'honoring' -> 'honorable', since the product's
 * own 5-type taxonomy renamed that tier.
 *
 * Expects $this to be an Achievement loaded through User::achievements()
 * (i.e. via the belongsToMany, so ->pivot is an AchievementAssignment).
 */
class AchievementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => (int) $this->pivot->id,
            'user_id'    => (int) $this->pivot->user_id,
            'name'       => $this->name,
            'rank'       => strtolower($this->type),
            // Nullable — FE substitutes its own default asset when null.
            'image_url'  => $this->image_url
                ? (str_starts_with($this->image_url, 'http')
                    ? $this->image_url
                    : Storage::disk('public')->url($this->image_url))
                : null,
            'awarded_at' => $this->pivot->assigned_at?->toIso8601String(),
        ];
    }
}
