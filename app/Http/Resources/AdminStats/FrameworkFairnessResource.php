<?php

namespace App\Http\Resources\AdminStats;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the FrameworkFairnessService payload (already-shaped array). */
class FrameworkFairnessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stat'          => $this->resource['stat'],
            'min_n_debates' => $this->resource['min_n_debates'],
            'frameworks'    => $this->resource['frameworks'],
        ];
    }
}
