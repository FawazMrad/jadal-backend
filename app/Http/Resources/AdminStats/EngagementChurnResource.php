<?php

namespace App\Http\Resources\AdminStats;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the EngagementChurnService payload (already-shaped array). */
class EngagementChurnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stat'         => $this->resource['stat'],
            'generated_at' => $this->resource['generated_at'],
            'entries'      => $this->resource['entries'],
            'meta'         => $this->resource['meta'],
        ];
    }
}
