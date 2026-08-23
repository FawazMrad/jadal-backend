<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\BestSpeakerRequest;
use App\Http\Requests\Stats\ScoreRankingRequest;
use App\Http\Requests\Stats\StatsFilterRequest;
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
        if ($guard = $this->guardSubjectIsDebater($debater)) {
            return $guard;
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
        if ($guard = $this->guardSubjectIsDebater($debater)) {
            return $guard;
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
        if ($guard = $this->guardSubjectIsDebater($debater)) {
            return $guard;
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
        if ($guard = $this->guardSubjectIsDebater($debater)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);

        return $this->success($this->service->scoreRanking($rows, $f), 'Score ranking retrieved.');
    }

    public function improvement(StatsFilterRequest $request, User $debater): JsonResponse
    {
        if ($guard = $this->guardSubjectIsDebater($debater)) {
            return $guard;
        }
        $f = StatsFilter::fromArray($request->validated());
        $rows = $this->service->participationRows($debater, $f);

        return $this->success($this->service->improvement($rows, $f), 'Improvement index retrieved.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * These five metrics are all derived from debating performance,
     * so they are meaningless for a judge or trainer subject. Previously such a
     * request returned 200 with empty aggregates, which reads as "this judge has
     * a 0% win rate" rather than "this question does not apply". Reject instead.
     *
     * Note this guards the SUBJECT, not the viewer: since spec made
     * statistics public, any authenticated user may read any debater's stats,
     * and the old stats_visible / self / supervising-coach gate is gone.
     */
    private function guardSubjectIsDebater(User $debater): ?JsonResponse
    {
        if ($debater->role === 'debater') {
            return null;
        }

        return $this->error(
            'هذه الإحصائيات متاحة للمتناظرين فقط. | These statistics are only available for debaters.',
            ['role' => $debater->role],
            422
        );
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

}
