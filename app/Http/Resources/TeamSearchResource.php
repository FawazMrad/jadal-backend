<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TeamSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $leader = $this->whenLoaded('leader');

        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'logo_url'      => $this->logo_url
                ? Storage::url($this->logo_url)
                : null,
            'members_count' => $this->members_count ?? 0,
            'trainer'       => $leader ? [
                'id'         => $leader->id,
                'name'       => $leader->name,
                'avatar_url' => $leader->avatar_url
                    ? Storage::url($leader->avatar_url)
                    : null,
            ] : null,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
