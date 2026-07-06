<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Stats\ActivityStatsService;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;

/**
 * V2 §7 — activity/participation scoring. One shared computation regardless
 * of role (registration+attendance+viewing+penalty all apply to any user),
 * exposed under all three existing per-role stat prefixes so it rides
 * alongside win-rate/avg-score/etc. (debater) and attendance (trainer/judge)
 * as an additional "kind" on the same per-user analysis screen — same auth
 * policy as those existing endpoints.
 */
class ActivityStatsController extends Controller
{
    public function __construct(private ActivityStatsService $service) {}

    public function debater(StatsFilterRequest $request, User $debater): JsonResponse
    {
        return $this->respond($request, $debater);
    }

    public function trainer(StatsFilterRequest $request, User $trainer): JsonResponse
    {
        return $this->respond($request, $trainer);
    }

    public function judge(StatsFilterRequest $request, User $judge): JsonResponse
    {
        return $this->respond($request, $judge);
    }

    private function respond(StatsFilterRequest $request, User $target): JsonResponse
    {
        if (! $this->canView($request->user(), $target)) {
            return $this->error('هذا المستخدم أخفى إحصائياته. | This user has hidden their statistics.', [], 403);
        }

        $f = StatsFilter::fromArray($request->validated());

        return $this->success($this->service->activity($target, $f), 'Activity score retrieved.');
    }

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
}
