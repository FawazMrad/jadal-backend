<?php

namespace App\Http\Requests\AdminStats;

use App\Services\AdminStats\ComplaintAccountabilityService;
use Illuminate\Validation\Rule;

class ComplaintAccountabilityRequest extends BaseAdminStatsRequest
{
    public function rules(): array
    {
        return $this->universalRules() + [
            'target_role'          => ['sometimes', 'nullable', Rule::in(['debater', 'trainer', 'judge', 'chair'])],
            'status'               => ['sometimes', 'nullable', Rule::in(ComplaintAccountabilityService::STATUSES)],
            'min_debates_involved' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function targetRole(): ?string
    {
        $v = $this->validated('target_role');

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    public function status(): ?string
    {
        $v = $this->validated('status');

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    public function minDebatesInvolved(): int
    {
        return (int) $this->validated('min_debates_involved', 3);
    }
}
