<?php

namespace App\Http\Resources\AdminStats;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps the ComplaintAccountabilityService payload (already-shaped array). */
class ComplaintAccountabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stat'               => $this->resource['stat'],
            'unattributed_total' => $this->resource['unattributed_total'],
            'entries'            => $this->resource['entries'],
        ];
    }
}
