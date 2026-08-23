<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\User;
use App\Services\Stats\ActivityStatsService;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;

/**
 * activity/participation scoring. One shared computation regardless
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

    /**
     * Statistics are public for every user, so there is no
     * visibility gate here any more. Any authenticated user may read any user's
     * activity score; the route's auth middleware is the only check.
     */
    private function respond(StatsFilterRequest $request, User $target): JsonResponse
    {
        $f = StatsFilter::fromArray($request->validated());

        return $this->success($this->service->activity($target, $f), 'Activity score retrieved.');
    }

}
