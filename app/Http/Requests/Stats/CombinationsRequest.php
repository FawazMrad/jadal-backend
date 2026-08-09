<?php

namespace App\Http\Requests\Stats;

use Illuminate\Validation\Rule;

/**
 * MF_FU §4 — line-up analysis filters.
 *
 * Extends the shared stats filter so `from`/`to`/`frameworks` keep identical
 * semantics and error messages. `positions`, `group_by` and `series` are
 * accepted by the parent but carry no meaning here: a combination is already
 * an aggregate over the whole window, so there is nothing to bucket or split.
 */
class CombinationsRequest extends StatsFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'metric'      => ['nullable', Rule::in(['win_rate', 'avg_score'])],
            'min_debates' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit'       => ['nullable', 'integer', 'min:1', 'max:' . (int) config('debate.stats.combination_max_limit', 50)],
        ]);
    }
}
