<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\BestSpeakerRequest;
use App\Http\Requests\Stats\ScoreRankingRequest;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\DebaterStatsService;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DebaterStatsController extends Controller
{
    public function __construct(private DebaterStatsService $service) {}

    public function winRate(StatsFilterRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);
        if ($guard = $this->guardMonthSpan($f, $rows)) {
            return $guard;
        }

        return $this->success($this->service->winRate($rows, $f), 'Win rate retrieved.');
    }

    public function avgScore(StatsFilterRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);
        if ($guard = $this->guardMonthSpan($f, $rows)) {
            return $guard;
        }

        return $this->success($this->service->avgScore($rows, $f), 'Average score retrieved.');
    }

    public function bestSpeaker(BestSpeakerRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);
        if ($guard = $this->guardMonthSpan($f, $rows)) {
            return $guard;
        }

        return $this->success($this->service->bestSpeaker($rows, $f), 'Best speaker stats retrieved.');
    }

    public function scoreRanking(ScoreRankingRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);

        return $this->success($this->service->scoreRanking($rows, $f), 'Score ranking retrieved.');
    }

    public function improvement(StatsFilterRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);

        return $this->success($this->service->improvement($rows, $f), 'Improvement index retrieved.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * V2 §9 — stats are public by default (any authenticated user may view);
     * self/admin/supervising-coach ALWAYS see it regardless, everyone else is
     * blocked only when the target has opted out (`stats_visible = false`).
     * A coach supervises a debater who is a CURRENT member of any team the
     * coach created (teams.created_by = coach, team_members.status = 'current').
     */
    private function canView(User $viewer, User $debater): bool
    {
        if ($viewer->role === 'admin') {
            return true;
        }
        if ((int) $viewer->id === (int) $debater->id) {
            return true;
        }
        if ($this->isSupervisingCoach($viewer, $debater)) {
            return true;
        }

        return (bool) $debater->stats_visible;
    }

    private function isSupervisingCoach(User $viewer, User $debater): bool
    {
        if ($viewer->role !== 'trainer') {
            return false;
        }

        return TeamMember::where('user_id', $debater->id)
            ->where('status', 'current')
            ->whereHas('team', fn ($q) => $q->where('created_by', $viewer->id))
            ->exists();
    }

    private function guardMonthSpan(StatsFilter $f, Collection $rows): ?JsonResponse
    {
        if ($f->groupBy === 'month' && ! ($f->from && $f->to)) {
            $guard = (int) config('debate.stats.month_span_guard', 24);
            if ($this->service->monthsSpan($rows) > $guard) {
                return $this->error(
                    "group_by=month requires explicit from/to when the history spans more than {$guard} months.",
                    [], 422
                );
            }
        }

        return null;
    }

    private function forbidden(): JsonResponse
    {
        return $this->error('غير مصرح بعرض إحصائيات هذا المتناظر. | Not authorized to view this debater\'s stats.', [], 403);
    }
}
