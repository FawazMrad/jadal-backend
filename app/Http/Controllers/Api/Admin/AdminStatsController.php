<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\AdminStats\ComplaintAccountabilityExport;
use App\Exports\AdminStats\EngagementChurnExport;
use App\Exports\AdminStats\FrameworkFairnessExport;
use App\Exports\AdminStats\LeaderboardExport;
use App\Exports\AdminStats\PlatformHealthExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminStats\ComplaintAccountabilityRequest;
use App\Http\Requests\AdminStats\EngagementChurnRequest;
use App\Http\Requests\AdminStats\FrameworkFairnessRequest;
use App\Http\Requests\AdminStats\LeaderboardRequest;
use App\Http\Requests\AdminStats\PlatformHealthRequest;
use App\Http\Resources\AdminStats\ComplaintAccountabilityResource;
use App\Http\Resources\AdminStats\EngagementChurnResource;
use App\Http\Resources\AdminStats\FrameworkFairnessResource;
use App\Http\Resources\AdminStats\LeaderboardResource;
use App\Http\Resources\AdminStats\PlatformHealthResource;
use App\Services\AdminStats\AdminStatFilters;
use App\Services\AdminStats\ComplaintAccountabilityService;
use App\Services\AdminStats\EngagementChurnService;
use App\Services\AdminStats\FrameworkFairnessService;
use App\Services\AdminStats\PlatformHealthService;
use App\Services\AdminStats\PlatformLeaderboardService;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin-only, platform-wide statistics (5 stats × JSON + Excel export).
 * Aggregation lives in one service per stat — each export downloads exactly
 * what its JSON endpoint computes, filters included, never a second query
 * path. Responses are cached (see StatsCache) for 10 minutes per
 * stat+filter combination.
 */
class AdminStatsController extends Controller
{
    public function __construct(
        private FrameworkFairnessService $fairness,
        private PlatformLeaderboardService $leaderboard,
        private PlatformHealthService $health,
        private EngagementChurnService $churn,
        private ComplaintAccountabilityService $complaints,
    ) {}

    // ── Stat 1: framework fairness ─────────────────────────────────────────

    public function frameworkFairness(FrameworkFairnessRequest $request): JsonResponse
    {
        $payload = $this->fairness->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->minNDebates()
        );

        return $this->success(
            new FrameworkFairnessResource($payload),
            'تم جلب مؤشر توازن الأطر. | Framework fairness retrieved.'
        );
    }

    public function frameworkFairnessExport(FrameworkFairnessRequest $request): BinaryFileResponse
    {
        $payload = $this->fairness->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->minNDebates()
        );

        return Excel::download(
            new FrameworkFairnessExport($payload, $request->validated()),
            $this->filename('framework-fairness')
        );
    }

    // ── Stat 2: platform leaderboards ──────────────────────────────────────

    public function leaderboard(LeaderboardRequest $request): JsonResponse
    {
        $payload = $this->leaderboard->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->board(),
            $request->limit(),
            $request->minNDebates()
        );

        return $this->success(
            new LeaderboardResource($payload),
            'تم جلب لوحة الصدارة. | Leaderboard retrieved.'
        );
    }

    public function leaderboardExport(LeaderboardRequest $request): BinaryFileResponse
    {
        $payload = $this->leaderboard->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->board(),
            $request->limit(),
            $request->minNDebates()
        );

        return Excel::download(
            new LeaderboardExport($payload, $request->validated()),
            $this->filename('leaderboard-' . $request->board())
        );
    }

    // ── Stat 3: platform growth & health ───────────────────────────────────

    public function platformHealth(PlatformHealthRequest $request): JsonResponse
    {
        $f = AdminStatFilters::fromArray($request->validated());

        if ($guard = $this->guardMonthSpan($f)) {
            return $guard;
        }

        $payload = $this->health->compute($f, $request->series());

        return $this->success(
            new PlatformHealthResource($payload),
            'تم جلب مؤشرات نمو المنصة. | Platform health retrieved.'
        );
    }

    public function platformHealthExport(PlatformHealthRequest $request): BinaryFileResponse|JsonResponse
    {
        $f = AdminStatFilters::fromArray($request->validated());

        if ($guard = $this->guardMonthSpan($f)) {
            return $guard;
        }

        $payload = $this->health->compute($f, $request->series());

        return Excel::download(
            new PlatformHealthExport($payload, $request->validated()),
            $this->filename('platform-health')
        );
    }

    // ── Stat 4: engagement & churn risk ────────────────────────────────────

    public function engagementChurn(EngagementChurnRequest $request): JsonResponse
    {
        $payload = $this->churn->compute($request->params());

        return $this->success(
            new EngagementChurnResource($payload),
            'تم جلب مؤشرات التفاعل والانقطاع. | Engagement & churn retrieved.'
        );
    }

    public function engagementChurnExport(EngagementChurnRequest $request): BinaryFileResponse
    {
        // Export carries EVERY matching debater, not one page.
        $payload = $this->churn->computeAll($request->params());

        return Excel::download(
            new EngagementChurnExport($payload, $request->params()),
            $this->filename('engagement-churn')
        );
    }

    // ── Stat 5: complaint accountability ───────────────────────────────────

    public function complaintAccountability(ComplaintAccountabilityRequest $request): JsonResponse
    {
        $payload = $this->complaints->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->targetRole(),
            $request->status(),
            $request->minDebatesInvolved()
        );

        return $this->success(
            new ComplaintAccountabilityResource($payload),
            'تم جلب لوحة الشكاوى والمساءلة. | Complaint accountability retrieved.'
        );
    }

    public function complaintAccountabilityExport(ComplaintAccountabilityRequest $request): BinaryFileResponse
    {
        $payload = $this->complaints->compute(
            AdminStatFilters::fromArray($request->validated()),
            $request->targetRole(),
            $request->status(),
            $request->minDebatesInvolved()
        );

        return Excel::download(
            new ComplaintAccountabilityExport($payload, $request->validated()),
            $this->filename('complaint-accountability')
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Same guard the mobile stats module applies: month buckets without an
     * explicit from+to are rejected once the data span exceeds the configured
     * limit (unbounded month series make useless payloads AND spreadsheets).
     */
    private function guardMonthSpan(AdminStatFilters $f): ?JsonResponse
    {
        if ($f->violatesMonthSpanGuard($this->health->earliestDataPoint())) {
            $guard = (int) config('debate.stats.month_span_guard', 24);

            return $this->error(
                "group_by=month requires explicit from/to when the history spans more than {$guard} months.",
                [], 422
            );
        }

        return null;
    }

    private function filename(string $stat): string
    {
        return 'jadal-' . $stat . '-' . now()->format('Ymd-Hi') . '.xlsx';
    }
}
