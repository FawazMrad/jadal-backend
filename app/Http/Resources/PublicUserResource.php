<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PublicUserResource extends JsonResource
{
    /**
     * Guest projection: nulls `points` while keeping every key and type.
     * This resource never carried email/phone, so `points` is its only PII.
     *
     * Defaults to false, so all pre-existing call sites are unaffected.
     */
    public function __construct($resource, private bool $stripPii = false)
    {
        parent::__construct($resource);
    }

    /**
     * Map a collection to guest-safe instances. `::collection()` cannot forward
     * constructor arguments, so callers needing stripping must go through here.
     */
    public static function guestCollection(mixed $resource): array
    {
        return collect($resource)
            ->map(fn ($user) => new self($user, true))
            ->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'role'       => $this->role,
            'avatar_url' => $this->avatar_url
                ? Storage::url($this->avatar_url)
                : null,
            'points'     => $this->stripPii ? null : $this->points,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
