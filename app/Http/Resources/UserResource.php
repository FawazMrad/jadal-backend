<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'role'              => $this->role,
            'status'            => $this->status,
            'avatar_url'        => $this->avatar_url
                ? (str_starts_with($this->avatar_url, 'http')
                    ? $this->avatar_url
                    : Storage::disk('public')->url($this->avatar_url))
                : null,
            'phone'             => $this->phone,
            'points'            => $this->points,
            'lang'              => $this->lang,
            'theme'             => $this->theme,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
