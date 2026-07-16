<?php

namespace App\Http\Requests\AdminStats;

class FrameworkFairnessRequest extends BaseAdminStatsRequest
{
    public function rules(): array
    {
        return $this->universalRules() + [
            // group_by is not applicable here — the response has no time
            // dimension (one row per framework), so it is not accepted.
            'min_n_debates' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function minNDebates(): int
    {
        return (int) $this->validated('min_n_debates', 5);
    }
}
