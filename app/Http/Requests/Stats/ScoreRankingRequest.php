<?php

namespace App\Http\Requests\Stats;

use Illuminate\Validation\Rule;

class ScoreRankingRequest extends StatsFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'ranking_mode' => ['required', Rule::in(['top', 'bottom', 'latest', 'earliest'])],
            'limit'        => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
    }
}
