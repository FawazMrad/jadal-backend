<?php

namespace App\Services\Points;

use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use App\Services\Stats\DebaterStatsService;
use App\Services\Stats\StatsFilter;
use App\Services\Stats\TeamStatsService;
use Illuminate\Support\Facades\Storage;

/**
 * V2 §3 — top-10 leaderboards. All-time (no date filter — that's what the
 * work order's endpoint signature asks for; per-user/team stats screens
 * already offer date-range filtering separately).
 *
 * `win_rate`/`avg_score`/`best_speaker`/`improvement` for debaters explicitly
 * REUSE DebaterStatsService (per the work order: "reuse whatever your
 * existing debater-stats pipeline already computes") rather than a second,
 * parallel aggregation — one candidate debater at a time. This costs one
 * extra query per candidate versus a single aggregate SQL pass; acceptable
 * for a top-10 ranking on this platform's scale, and it guarantees the
 * leaderboard number always matches what that debater's own stats screen
 * shows (same code path, zero drift risk). Flagged in case it ever needs a
 * cached/materialized ranking at larger scale.
 *
 * Excludes users with stats_visible=false entirely (see §9) — a leaderboard
 * ranking is unambiguously "your performance data made public." Teams have no
 * per-team visibility flag, so team entries are never excluded, and `is_random`
 * (ad-hoc/one-off) teams are excluded from ranking since they're not a
 * persistent entity worth ranking.
 */
class LeaderboardService
{
    private const DEBATER_METRICS = ['points', 'win_rate', 'avg_score', 'best_speaker', 'improvement'];
    private const TEAM_METRICS = ['points', 'win_rate', 'avg_score', 'improvement'];

    public function __construct(
        private DebaterStatsService $debaterStats,
        private TeamStatsService $teamStats,
    ) {}

    public static function debaterMetrics(): array
    {
        return self::DEBATER_METRICS;
    }

    public static function teamMetrics(): array
    {
        return self::TEAM_METRICS;
    }

    public function debaters(string $metric, int $limit): array
    {
        if ($metric === 'points') {
            $users = User::where('role', 'debater')
                ->where('stats_visible', true)
                ->orderByDesc('points')
                ->limit($limit)
                ->get(['id', 'name', 'avatar_url', 'points']);

            return $users->values()->map(fn (User $u, int $i) => $this->userEntry($i + 1, $u, (int) $u->points))->all();
        }

        $filter = StatsFilter::fromArray([]);

        $candidateIds = DebateParticipant::where('role', 'debater')
            ->where('status', 'approved')
            ->whereHas('debate', fn ($q) => $q->where('status', 'completed')->whereNotNull('result_revealed_at'))
            ->distinct()
            ->pluck('user_id');

        $users = User::whereIn('id', $candidateIds)->where('stats_visible', true)->get(['id', 'name', 'avatar_url']);

        $scored = [];
        foreach ($users as $user) {
            $rows = $this->debaterStats->participationRows($user, $filter);
            if ($rows->isEmpty()) {
                continue;
            }

            $value = match ($metric) {
                'win_rate'     => $this->scalarFromBuckets($this->debaterStats->winRate($rows, $filter)),
                'avg_score'    => $this->scalarFromBuckets($this->debaterStats->avgScore($rows, $filter)),
                'best_speaker' => $this->countFromBuckets($this->debaterStats->bestSpeaker($rows, $filter)),
                'improvement'  => $this->debaterStats->improvement($rows, $filter)['index'] ?? null,
                default        => null,
            };

            if ($value === null) {
                continue;
            }
            $scored[] = ['user' => $user, 'value' => $value];
        }

        usort($scored, fn ($a, $b) => $b['value'] <=> $a['value']);

        return collect(array_slice($scored, 0, $limit))
            ->values()
            ->map(fn (array $e, int $i) => $this->userEntry($i + 1, $e['user'], $e['value']))
            ->all();
    }

    public function teams(string $metric, int $limit): array
    {
        if ($metric === 'points') {
            $teams = Team::where('is_random', false)
                ->orderByDesc('points')
                ->limit($limit)
                ->get(['id', 'name', 'points']);

            return $teams->values()->map(fn (Team $t, int $i) => $this->teamEntry($i + 1, $t, (int) $t->points))->all();
        }

        $filter = StatsFilter::fromArray([]);
        $teams = Team::where('is_random', false)->get(['id', 'name']);

        $scored = [];
        foreach ($teams as $team) {
            $value = match ($metric) {
                'win_rate'    => $this->teamStats->winRate($team, $filter),
                'avg_score'   => $this->teamStats->avgScore($team, $filter),
                'improvement' => $this->teamStats->improvement($team, $filter),
                default       => null,
            };

            if ($value === null) {
                continue;
            }
            $scored[] = ['team' => $team, 'value' => $value];
        }

        usort($scored, fn ($a, $b) => $b['value'] <=> $a['value']);

        return collect(array_slice($scored, 0, $limit))
            ->values()
            ->map(fn (array $e, int $i) => $this->teamEntry($i + 1, $e['team'], $e['value']))
            ->all();
    }

    private function userEntry(int $rank, User $user, float|int $value): array
    {
        return [
            'rank'       => $rank,
            'user_id'    => (int) $user->id,
            'name'       => $user->name,
            'avatar_url' => $user->avatar_url
                ? (str_starts_with($user->avatar_url, 'http') ? $user->avatar_url : Storage::disk('public')->url($user->avatar_url))
                : null,
            'value'      => $value,
        ];
    }

    private function teamEntry(int $rank, Team $team, float|int $value): array
    {
        return [
            'rank'      => $rank,
            'team_id'   => (int) $team->id,
            'team_name' => $team->name,
            // Teams have no logo/avatar concept in the schema today — always null.
            'logo_url'  => null,
            'value'     => $value,
        ];
    }

    private function scalarFromBuckets(array $result): ?float
    {
        $buckets = $result['buckets'] ?? [];

        return empty($buckets) ? null : ($buckets[0]['series'][0]['value'] ?? null);
    }

    private function countFromBuckets(array $result): ?int
    {
        $buckets = $result['buckets'] ?? [];

        return empty($buckets) ? null : ($buckets[0]['series'][0]['count'] ?? null);
    }
}
