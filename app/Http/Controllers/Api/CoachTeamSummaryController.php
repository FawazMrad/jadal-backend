<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\ActivityStatsService;
use App\Services\Stats\StatsFilter;
use App\Services\Stats\TeamStatsService;
use Illuminate\Http\JsonResponse;

/**
 * V2 §3 — coach team-summary: one snapshot averaged across every team a coach
 * currently or has ever trained (teams.created_by), non-random only.
 *
 * Scope note (a deliberate simplification, not the full spec): this returns a
 * single scalar per metric — the average of each team's all-time (or
 * date-range-filtered) figure — rather than the debater API's bucketed
 * chart series. Averaging N teams' own bucketed series into one combined
 * series is a materially different computation; the work order's own wording
 * was "suggested fields", not a strict shape. Flagged in the response doc —
 * happy to build the full bucketed version if that's actually needed.
 *
 * `team_avg_active` is different in kind from the other three: it's the
 * average of the §7 activity SCORE across the coach's CURRENT teams'
 * CURRENT members (not a team-level computation), per the work order's own
 * phrasing ("averaged across the coach's team members").
 */
class CoachTeamSummaryController extends Controller
{
    public function __construct(
        private TeamStatsService $teamStats,
        private ActivityStatsService $activityStats,
    ) {}

    /**
     * Frontend spec §6.4 — statistics are public for every user, so the
     * previous self/admin/stats_visible gate is gone. Any authenticated user
     * may read any coach's team summary.
     */
    public function show(StatsFilterRequest $request, User $trainer): JsonResponse
    {
        $f = StatsFilter::fromArray($request->validated());

        $teams = Team::where('created_by', $trainer->id)->where('is_random', false)->get();

        $winRates = [];
        $avgScores = [];
        $improvements = [];
        foreach ($teams as $team) {
            if (($v = $this->teamStats->winRate($team, $f)) !== null) {
                $winRates[] = $v;
            }
            if (($v = $this->teamStats->avgScore($team, $f)) !== null) {
                $avgScores[] = $v;
            }
            if (($v = $this->teamStats->improvement($team, $f)) !== null) {
                $improvements[] = $v;
            }
        }

        $currentMemberIds = TeamMember::where('status', 'current')
            ->whereIn('team_id', $teams->where('status', 'active')->pluck('id'))
            ->pluck('user_id')
            ->unique();

        $activeScores = [];
        foreach (User::whereIn('id', $currentMemberIds)->get() as $member) {
            $activeScores[] = $this->activityStats->activity($member, $f)['totals']['value'];
        }

        return $this->success([
            'stat'               => 'team_summary',
            'teams_counted'      => $teams->count(),
            'team_avg_improvement' => $this->avg($improvements),
            'team_avg_win_rate'    => $this->avg($winRates),
            'team_avg_score'       => $this->avg($avgScores),
            'team_avg_active'      => $this->avg($activeScores),
        ], 'Coach team summary retrieved.');
    }

    private function avg(array $values): ?float
    {
        return empty($values) ? null : round(array_sum($values) / count($values), 4);
    }

}
