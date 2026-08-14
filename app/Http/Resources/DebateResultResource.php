<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebateResultResource extends JsonResource
{
    /**
     * Forwarded to the nested judge UserResource. A revealed result is public
     * (§Q6 — guests see it), but the submitting judge's contact details are not.
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
            'id'            => $this->id,
            'debate_id'     => $this->debate_id,
            'judge'         => $this->whenLoaded(
                'judge',
                fn () => new UserResource($this->judge, $this->stripPii)
            ),
            'contributing_judges' => $this->contributing_judges,
            'winning_side'  => $this->winning_side,
            'scores'        => $this->scores,
            'summary_notes' => $this->summary_notes,
            'submitted_at'  => $this->submitted_at?->toIso8601String(),
        ];
    }
}
