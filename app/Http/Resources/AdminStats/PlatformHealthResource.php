<?php

namespace App\Http\Resources\AdminStats;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the PlatformHealthService payload (already-shaped array). */
class PlatformHealthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stat'    => $this->resource['stat'],
            'series'  => $this->resource['series'],
            'buckets' => array_map(function (array $b) {
                // Keep the breakdown a JSON object even when empty ({} not []),
                // so frontend typing stays stable across buckets.
                $b['cancellation_breakdown'] = (object) $b['cancellation_breakdown'];

                return $b;
            }, $this->resource['buckets']),
        ];
    }
}
