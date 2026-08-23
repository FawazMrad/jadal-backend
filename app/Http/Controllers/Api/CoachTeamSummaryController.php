<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\CoachTeamSummaryRequest;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\ActivityStatsService;
use App\Services\Stats\StatsFilter;
use App\Services\Stats\TeamStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Coach team-summary: one snapshot averaged across every team a coach
 * currently or has ever trained (teams.created_by), non-random only.
 *
 * Scope note — a deliberate simplification: this returns a single scalar per
 * metric (the average of each team's all-time, or date-range-filtered, figure)
 * rather than the debater API's bucketed chart series. Collapsing N teams' own
 * bucketed series into one combined series is a materially different
 * computation, and no consumer needs it yet. Revisit if a chart is ever
 * required here.
 *
 * `team_avg_active` is different in kind from the other three: it averages the
 * activity SCORE across the CURRENT members of the coach's CURRENT teams, so
 * it is a per-member figure rather than a team-level one.
 */
class CoachTeamSummaryController extends Controller
{
    public function __construct(
        private TeamStatsService $teamStats,
        private ActivityStatsService $activityStats,
    ) {}

    /**
     * Statistics are public for every user, so the
     * previous self/admin/stats_visible gate is gone. Any authenticated user
     * may read any coach's team summary.
     */
    public function show(CoachTeamSummaryRequest $request, User $trainer): JsonResponse
    {
        $f = StatsFilter::fromArray($request->validated());

        $teams = Team::where('created_by', $trainer->id)->where('is_random', false)->get();

        // Optional narrowing to one team. Omitted keeps the
        // all-teams average byte-for-byte as before. A team this coach does not
        // train is a 403 rather than a 404 by explicit request: the id may well
        // exist, and saying "not found" for someone else's team is a lie that
        // also leaks less usefully than a plain refusal.
        if ($request->filled('team_id')) {
            $teamId = (int) $request->query('team_id');
            $teams  = $teams->where('id', $teamId)->values();

            if ($teams->isEmpty()) {
                return $this->error(
                    'غير مصرح. هذا الفريق ليس ضمن فرق هذا المدرب. | Unauthorized. That team is not coached by this trainer.',
                    ['team_id' => $teamId],
                    403
                );
            }
        }

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
