<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * When true, contact/scoring PII is nulled while every key and type stays
     * exactly as-is, so a client parser needs no change. Used by the guest
     * live-state projection and by GET /teams/{id} for callers who are
     * not the coach/leader/a member.
     *
     * Defaults to false, so all pre-existing call sites are unaffected.
     */
    public function __construct($resource, private bool $stripPii = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->stripPii ? null : $this->email,
            'role'              => $this->role,
            'status'            => $this->status,
            'avatar_url'        => $this->avatar_url
                ? (str_starts_with($this->avatar_url, 'http')
                    ? $this->avatar_url
                    : Storage::disk('public')->url($this->avatar_url))
                : null,
            'phone'             => $this->stripPii ? null : $this->phone,
            'points'            => $this->stripPii ? null : $this->points,
            // The spec's rule is "names and avatar_url only", so date-of-birth,
            // derived age and location are stripped alongside the three fields
            // named explicitly in — they are personal data by any reading.
            'birth_date'        => $this->stripPii ? null : $this->birth_date?->toDateString(),
            'age'               => $this->stripPii ? null : $this->birth_date?->age,
            'location'          => $this->stripPii ? null : $this->location,
            'lang'              => $this->lang,
            'theme'             => $this->theme,
            'email_verified_at' => $this->stripPii ? null : $this->email_verified_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
