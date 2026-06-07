<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'role'       => $this->role,
            'avatar_url' => $this->avatar_url
                ? Storage::url($this->avatar_url)
                : null,
            'points'     => $this->points,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
