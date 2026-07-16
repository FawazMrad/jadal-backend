<?php

namespace App\Http\Resources\AdminStats;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the PlatformLeaderboardService payload (already-shaped array). */
class LeaderboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stat'          => $this->resource['stat'],
            'board'         => $this->resource['board'],
            'min_n_debates' => $this->resource['min_n_debates'],
            'entries'       => $this->resource['entries'],
        ];
    }
}
