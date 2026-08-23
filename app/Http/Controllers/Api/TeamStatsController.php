<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\CombinationsRequest;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\ActivityStatsService;
use App\Services\Stats\StatsFilter;
use App\Services\Stats\TeamStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * per-team analytics for the coach's team picker.
 *
 * The four metric endpoints return the SAME envelopes as the debater API
 * (win-rate/avg-score → bucketed series, improvement → ImprovementStat,
 * activity → ActivityStat) so the client reuses its existing parsers.
 *
 * Unlike the debater stats — which made public — these are gated to the
 * coach of the team and admins. A line-up analysis is competitive information:
 * it tells anyone who reads it which three people to prepare against.
 */
class TeamStatsController extends Controller
{
    public function __construct(
        private TeamStatsService $teamStats,
        private ActivityStatsService $activityStats,
    ) {}

    public function winRate(StatsFilterRequest $request, Team $team): JsonResponse
    {
        if ($guard = $this->guard($request, $team)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->teamStats->winRateStat($this->teamStats->participationRows($team, $f), $f),
            'Team win rate retrieved.'
        );
    }

    public function avgScore(StatsFilterRequest $request, Team $team): JsonResponse
    {
        if ($guard = $this->guard($request, $team)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->teamStats->avgScoreStat($this->teamStats->participationRows($team, $f), $f),
            'Team average score retrieved.'
        );
    }

    public function improvement(StatsFilterRequest $request, Team $team): JsonResponse
    {
        if ($guard = $this->guard($request, $team)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->teamStats->improvementStat($this->teamStats->participationRows($team, $f), $f),
            'Team improvement index retrieved.'
        );
    }

    /**
     * Team activity is the SUM of its current members' activity events, not a
     * team-level signal of its own — there is no such thing as "the team
     * registered" in the events table, only individuals doing things. The sum is
     * therefore proportional to squad size; `members_counted` rides along so the
     * client can divide to get the per-member average, which is exactly the
     * `team_avg_active` figure the coach summary reports.
     */
    public function activity(StatsFilterRequest $request, Team $team): JsonResponse
    {
        if ($guard = $this->guard($request, $team)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());

        $memberIds = TeamMember::where('team_id', $team->id)
            ->where('status', 'current')
            ->pluck('user_id');

        $members = User::whereIn('id', $memberIds)->get();

        $combined = null;
        foreach ($members as $member) {
            $one = $this->activityStats->activity($member, $f);
            $combined = $combined === null ? $one : $this->mergeActivity($combined, $one);
        }

        $combined ??= [
            'stat'     => 'activity',
            'grouping' => match ($f->groupBy) { 'year' => 'by_year', 'month' => 'by_month', default => 'none' },
            'buckets'  => [],
            'totals'   => ['value' => 0.0, 'breakdown' => $this->emptyBreakdown()],
        ];

        $combined['members_counted'] = $members->count();

        return $this->success($combined, 'Team activity retrieved.');
    }

    // ── — line-up (combination) analysis ────────────────────────────────────

    public function combinations(CombinationsRequest $request, Team $team): JsonResponse
    {
        if ($guard = $this->guard($request, $team)) {
            return $guard;
        }

        $v = $request->validated();
        $f = StatsFilter::fromArray($v);

        $metric      = $v['metric'] ?? 'win_rate';
        $minDebates  = (int) ($v['min_debates'] ?? config('debate.stats.combination_default_min_debates', 2));
        $limit       = (int) ($v['limit'] ?? config('debate.stats.combination_default_limit', 10));

        $rows   = $this->teamStats->participationRows($team, $f);
        $result = $this->teamStats->combinations($rows, $metric, $minDebates, $limit);

        // Resolve member identity once for every id across every returned combo.
        $ids = collect($result['combinations'])->flatMap(fn (array $c) => $c['member_ids'])->unique();
        $users = User::whereIn('id', $ids)->get(['id', 'name', 'avatar_url'])->keyBy('id');
        $currentMemberIds = TeamMember::where('team_id', $team->id)
            ->where('status', 'current')
            ->pluck('user_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $combinations = array_map(function (array $c) use ($users, $currentMemberIds) {
            return [
                'key'     => $c['key'],
                'size'    => $c['size'],
                'members' => array_map(fn (int $id) => [
                    'user_id'           => $id,
                    'name'              => $users[$id]->name ?? null,
                    'avatar_url'        => $this->avatarUrl($users[$id]->avatar_url ?? null),
                    'is_current_member' => in_array($id, $currentMemberIds, true),
                ], $c['member_ids']),
                'n_debates'      => $c['n_debates'],
                'wins'           => $c['wins'],
                'win_rate'       => $c['win_rate'],
                'avg_score'      => $c['avg_score'],
                'last_debate_at' => $c['last_debate_at']
                    ? \Carbon\Carbon::createFromTimestamp($c['last_debate_at'])->toIso8601String()
                    : null,
            ];
        }, $result['combinations']);

        $sizes = array_values(array_unique(array_column($result['combinations'], 'size')));

        return $this->success([
            'team_id'                  => (int) $team->id,
            'team_name'                => $team->name,
            'metric'                   => $metric,
            // Null when the window mixes formats — read `size` per combination.
            'combination_size'         => count($sizes) === 1 ? $sizes[0] : null,
            'total_debates_considered' => $result['total_debates_considered'],
            'distinct_combinations'    => $result['distinct_combinations'],
            'min_debates'              => $minDebates,
            'combinations'             => $combinations,
            'team_baseline'            => $this->teamStats->baseline($rows),
            'reason'                   => $this->combinationsReason($result, $rows->count(), $minDebates),
        ], 'Team combination analysis retrieved.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Explains an empty list instead of leaving the client to guess. Null when
     * there is something to show.
     */
    private function combinationsReason(array $result, int $rowCount, int $minDebates): ?string
    {
        if (! empty($result['combinations'])) {
            return null;
        }
        if ($rowCount === 0) {
            return 'no_debates_in_range';
        }
        if ($result['distinct_combinations'] === 0) {
            return 'no_scored_line_ups';
        }

        return 'below_min_debates';
    }

    private function avatarUrl(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        return str_starts_with($raw, 'http') ? $raw : Storage::disk('public')->url($raw);
    }

    /** Element-wise sum of two ActivityStat payloads (buckets keyed by label). */
    private function mergeActivity(array $a, array $b): array
    {
        $byLabel = [];
        foreach ([...$a['buckets'], ...$b['buckets']] as $bucket) {
            $label = $bucket['label'];
            if (! isset($byLabel[$label])) {
                $byLabel[$label] = ['label' => $label, 'value' => 0.0, 'breakdown' => $this->emptyBreakdown()];
            }
            $byLabel[$label]['value'] = round($byLabel[$label]['value'] + $bucket['value'], 2);
            foreach ($bucket['breakdown'] as $type => $vals) {
                $byLabel[$label]['breakdown'][$type]['points'] = round(
                    $byLabel[$label]['breakdown'][$type]['points'] + $vals['points'], 2
                );
                $byLabel[$label]['breakdown'][$type]['count'] += $vals['count'];
            }
        }
        ksort($byLabel);

        $totals = ['value' => round($a['totals']['value'] + $b['totals']['value'], 2), 'breakdown' => $this->emptyBreakdown()];
        foreach (['registration', 'attendance', 'viewing', 'penalty'] as $type) {
            $totals['breakdown'][$type]['points'] = round(
                $a['totals']['breakdown'][$type]['points'] + $b['totals']['breakdown'][$type]['points'], 2
            );
            $totals['breakdown'][$type]['count'] =
                $a['totals']['breakdown'][$type]['count'] + $b['totals']['breakdown'][$type]['count'];
        }

        return [
            'stat'     => 'activity',
            'grouping' => $a['grouping'],
            'buckets'  => array_values($byLabel),
            'totals'   => $totals,
        ];
    }

    private function emptyBreakdown(): array
    {
        return [
            'registration' => ['points' => 0.0, 'count' => 0],
            'attendance'   => ['points' => 0.0, 'count' => 0],
            'viewing'      => ['points' => 0.0, 'count' => 0],
            'penalty'      => ['points' => 0.0, 'count' => 0],
        ];
    }

    /**
     * 403 for a team the caller does not coach — including one that does not
     * exist would be a 404, but route-model binding already handles that.
     * Random teams are rejected outright: they are per-debate scaffolding, and
     * "which line-up works best" is meaningless for a roster that existed once.
     */
    private function guard($request, Team $team): ?JsonResponse
    {
        $user = $request->user();

        if ($team->is_random) {
            return $this->error(
                'هذه الإحصائيات غير متاحة للفرق العشوائية. | Statistics are not available for random teams.',
                ['is_random' => true],
                422
            );
        }

        if ($user->role === 'admin' || (int) $team->created_by === (int) $user->id) {
            return null;
        }

        return $this->error(
            'غير مصرح. هذه الإحصائيات متاحة لمدرب الفريق فقط. | Unauthorized. These statistics are available to the team coach only.',
            [],
            403
        );
    }
}
