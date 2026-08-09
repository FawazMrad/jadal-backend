<?php

namespace App\Http\Requests\Stats;

/** MF_FU §3.1a — the shared stats filter plus an optional single-team narrowing. */
class CoachTeamSummaryRequest extends StatsFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Existence is NOT validated here on purpose: a non-existent id and
            // another coach's id must be indistinguishable, and both answer 403
            // in the controller rather than 404/422.
            'team_id' => ['nullable', 'integer'],
        ]);
    }
}
