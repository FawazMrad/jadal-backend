<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
{
    /**
     * Forwarded to the nested UserResource — see TeamResource::$stripPii.
     *
     * Defaults to false, so all pre-existing call sites are unaffected.
     */
    public function __construct($resource, private bool $stripPii = false)
    {
        parent::__construct($resource);
    }

    /**
     * Map a collection to PII-stripped instances. `::collection()` cannot
     * forward constructor arguments, so callers must go through here.
     */
    public static function guestCollection(mixed $resource): array
    {
        return collect($resource)
            ->map(fn ($member) => new self($member, true))
            ->all();
    }

    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'user_id'   => $this->user_id,
            'user'      => $this->whenLoaded(
                'user',
                fn () => new UserResource($this->user, $this->stripPii)
            ),
            'priority'  => $this->priority,
            'status'    => $this->status,       // current | past
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
