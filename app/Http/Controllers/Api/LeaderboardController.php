<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Points\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** V2 §3 — top-10 leaderboards for debaters and teams. */
class LeaderboardController extends Controller
{
    public function __construct(private LeaderboardService $service) {}

    // ── GET /leaderboards/debaters?metric=&limit= ───────────────────────────────

    public function debaters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => ['required', Rule::in(LeaderboardService::debaterMetrics())],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $entries = $this->service->debaters($validated['metric'], (int) ($validated['limit'] ?? 10));

        return $this->success(
            ['metric' => $validated['metric'], 'entries' => $entries],
            'تم جلب لوحة الصدارة. | Leaderboard retrieved.'
        );
    }

    // ── GET /leaderboards/teams?metric=&limit= ──────────────────────────────────

    public function teams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => ['required', Rule::in(LeaderboardService::teamMetrics())],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $entries = $this->service->teams($validated['metric'], (int) ($validated['limit'] ?? 10));

        return $this->success(
            ['metric' => $validated['metric'], 'entries' => $entries],
            'تم جلب لوحة الصدارة. | Leaderboard retrieved.'
        );
    }
}
