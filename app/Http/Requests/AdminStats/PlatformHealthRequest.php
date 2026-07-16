<?php

namespace App\Http\Requests\AdminStats;

use Illuminate\Validation\Rule;

class PlatformHealthRequest extends BaseAdminStatsRequest
{
    public function rules(): array
    {
        return $this->universalRules() + [
            'group_by' => ['sometimes', Rule::in(['none', 'year', 'month'])],
            'series'   => ['sometimes', Rule::in(['none', 'role', 'debate_format'])],
        ];
    }

    public function series(): string
    {
        return (string) $this->validated('series', 'none');
    }
}
