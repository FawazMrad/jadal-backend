<?php

namespace App\Http\Requests\AdminStats;

use App\Services\AdminStats\PlatformLeaderboardService;
use Illuminate\Validation\Rule;

class LeaderboardRequest extends BaseAdminStatsRequest
{
    public function rules(): array
    {
        return $this->universalRules() + [
            'board'         => ['required', Rule::in(PlatformLeaderboardService::BOARDS)],
            'limit'         => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_n_debates' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function board(): string
    {
        return (string) $this->validated('board');
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', 10);
    }

    public function minNDebates(): int
    {
        return (int) $this->validated('min_n_debates', 3);
    }
}
