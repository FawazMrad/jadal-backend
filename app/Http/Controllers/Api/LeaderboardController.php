<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Points\LeaderboardService;
use App\Services\Stats\PositionCodes;
use App\Services\Stats\StatsFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * top-N leaderboards for debaters and teams.
 *
 * Supports the same filter set as the per-user statistics screens (date range,
 * and positions OR frameworks). Filters are threaded into that same
 * per-subject pipeline, so a filtered leaderboard value always equals the
 * subject's own filtered number.
 *
 * No `group_by`: a grouped top-N is not a single ranked list. The date range
 * covers the "this month / this year" use case.
 */
class LeaderboardController extends Controller
{
    public function __construct(private LeaderboardService $service) {}

    // ── GET /leaderboards/debaters?metric=&limit=&from=&to=&positions=&frameworks= ──

    public function debaters(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(LeaderboardService::debaterMetrics()));

        if ($error = $this->guardFilters($request, $validated['metric'])) {
            return $error;
        }

        $entries = $this->service->debaters(
            $validated['metric'],
            (int) ($validated['limit'] ?? 10),
            StatsFilter::fromArray($validated)
        );

        return $this->success(
            ['metric' => $validated['metric'], 'entries' => $entries],
            'تم جلب لوحة الصدارة. | Leaderboard retrieved.'
        );
    }

    // ── GET /leaderboards/teams?metric=&limit=&from=&to=&frameworks= ───────────

    public function teams(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(LeaderboardService::teamMetrics()));

        // A position is a per-debater speaking slot; it has no meaning for a
        // team aggregate, so it is rejected here rather than silently ignored.
        if ($request->filled('positions')) {
            return $this->error(
                'positions is not supported on the team leaderboard',
                ['positions' => ['positions is not supported on the team leaderboard']],
                422
            );
        }

        if ($error = $this->guardFilters($request, $validated['metric'])) {
            return $error;
        }

        $entries = $this->service->teams(
            $validated['metric'],
            (int) ($validated['limit'] ?? 10),
            StatsFilter::fromArray($validated)
        );

        return $this->success(
            ['metric' => $validated['metric'], 'entries' => $entries],
            'تم جلب لوحة الصدارة. | Leaderboard retrieved.'
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function rules(array $metrics): array
    {
        return [
            'metric'     => ['required', Rule::in($metrics)],
            'limit'      => ['sometimes', 'integer', 'min:1', 'max:50'],
            'from'       => ['sometimes', 'nullable', 'date_format:Y-m'],
            'to'         => ['sometimes', 'nullable', 'date_format:Y-m', 'after_or_equal:from'],
            'positions'  => ['sometimes', 'nullable', 'string'],
            'frameworks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Cross-field filter rules shared by both leaderboards:
     * - positions and frameworks are mutually exclusive;
     *  - every position code / framework id must be well-formed;
     *  - `metric=points` cannot honour ANY filter — users.points is a running
     *    Elo rating, not a per-debate quantity, so there is no "points as of
     *    month X" to compute. Rejecting is honest; returning the all-time
     *    ranking under a filtered heading would not be.
     */
    private function guardFilters(Request $request, string $metric): ?JsonResponse
    {
        $hasPositions  = $request->filled('positions');
        $hasFrameworks = $request->filled('frameworks');

        if ($hasPositions && $hasFrameworks) {
            return $this->error(
                'positions and frameworks are mutually exclusive',
                ['positions' => ['positions and frameworks are mutually exclusive']],
                422
            );
        }

        $filtered = $hasPositions || $hasFrameworks || $request->filled('from') || $request->filled('to');

        if ($metric === 'points' && $filtered) {
            return $this->error(
                'metric=points cannot be filtered — points is a cumulative rating, not a per-debate value. Use another metric or drop the filters.',
                ['metric' => ['metric=points does not support from/to/positions/frameworks']],
                422
            );
        }

        foreach ($this->csv($request, 'positions') as $code) {
            if (! in_array($code, PositionCodes::allValidCodes(), true)) {
                return $this->error("Invalid position code: {$code}.", ['positions' => ["Invalid position code: {$code}."]], 422);
            }
        }

        foreach ($this->csv($request, 'frameworks') as $fid) {
            if (! ctype_digit($fid)) {
                return $this->error("Invalid framework id: {$fid}.", ['frameworks' => ["Invalid framework id: {$fid}."]], 422);
            }
        }

        return null;
    }

    /** @return string[] */
    private function csv(Request $request, string $key): array
    {
        $raw = $request->input($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }
}
