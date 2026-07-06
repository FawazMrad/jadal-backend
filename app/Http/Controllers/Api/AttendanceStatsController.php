<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\AttendanceStatsService;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;

/**
 * Sprinkles §6.5 — attendance stats for all three roles.
 *
 * Authorization mirrors the existing DebaterStatsController policy:
 * self, admin, or (for debaters) the coach who currently supervises them.
 */
class AttendanceStatsController extends Controller
{
    public function __construct(private AttendanceStatsService $service) {}

    // ── GET /debaters/{debater}/stats/prep-attendance ───────────────────────────

    public function debater(StatsFilterRequest $request, User $debater): JsonResponse
    {
        if (! $this->canView($request->user(), $debater)) {
            return $this->forbidden();
        }

        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->service->attendance($debater, 'debater', $f),
            'Prep-room attendance retrieved.'
        );
    }

    // ── GET /trainers/{trainer}/stats/attendance ────────────────────────────────

    public function trainer(StatsFilterRequest $request, User $trainer): JsonResponse
    {
        if (! $this->canView($request->user(), $trainer)) {
            return $this->forbidden();
        }

        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->service->attendance($trainer, 'trainer', $f),
            'Coach attendance retrieved.'
        );
    }

    // ── GET /judges/{judge}/stats/attendance ────────────────────────────────────

    public function judge(StatsFilterRequest $request, User $judge): JsonResponse
    {
        if (! $this->canView($request->user(), $judge)) {
            return $this->forbidden();
        }

        $f = StatsFilter::fromArray($request->validated());

        return $this->success(
            $this->service->attendance($judge, 'judge', $f),
            'Judge attendance retrieved.'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * V2 §9 — stats are public by default (any authenticated user may view);
     * self/admin/supervising-coach ALWAYS see it, everyone else is blocked
     * only when the target has opted out (`stats_visible = false`).
     */
    private function canView(User $viewer, User $target): bool
    {
        if ($viewer->role === 'admin') {
            return true;
        }
        if ((int) $viewer->id === (int) $target->id) {
            return true;
        }
        if ($this->isSupervisingCoach($viewer, $target)) {
            return true;
        }

        return (bool) $target->stats_visible;
    }

    private function isSupervisingCoach(User $viewer, User $target): bool
    {
        if ($viewer->role !== 'trainer') {
            return false;
        }

        return TeamMember::where('user_id', $target->id)
            ->where('status', 'current')
            ->whereHas('team', fn ($q) => $q->where('created_by', $viewer->id))
            ->exists();
    }

    private function forbidden(): JsonResponse
    {
        return $this->error('غير مصرح بعرض هذه الإحصائيات. | Not authorized to view these statistics.', [], 403);
    }
}
