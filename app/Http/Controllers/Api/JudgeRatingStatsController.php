<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stats\StatsFilterRequest;
use App\Models\User;
use App\Services\Stats\JudgeRatingStatsService;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;

/**
 * MF_FU §5 — GET /judges/{judge}/stats/ratings.
 *
 * Readable by any authenticated user, matching every other per-user stat since
 * frontend spec §6.4 made statistics public — the stats screen opens from
 * public profiles, so a self/admin gate would break it. The payload is
 * aggregate-only, so nothing about who rated what leaks.
 */
class JudgeRatingStatsController extends Controller
{
    public function __construct(private JudgeRatingStatsService $service) {}

    public function show(StatsFilterRequest $request, User $judge): JsonResponse
    {
        // Mirrors DebaterStatsController::guardSubjectIsDebater — asking for a
        // debater's judging ratings is a category error, and answering 200 with
        // nulls reads as "this judge is unrated" rather than "wrong question".
        if ($judge->role !== 'judge') {
            return $this->error(
                'هذه الإحصائيات متاحة للقضاة فقط. | These statistics are only available for judges.',
                ['role' => $judge->role],
                422
            );
        }

        $f = StatsFilter::fromArray($request->validated());

        return $this->success($this->service->ratings($judge, $f), 'Judge ratings retrieved.');
    }
}
