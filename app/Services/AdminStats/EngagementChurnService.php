<?php

namespace App\Services\AdminStats;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stat 4 — Debater Engagement & Churn Risk.
 *
 * One grouped aggregate over (debater × completed debate) rows; the risk
 * label is computed inside the query so risk_filter and pagination compose
 * correctly (filtering must happen before LIMIT/OFFSET, and the page total
 * must count filtered rows). All date cutoffs are precomputed in PHP and sent
 * as bindings, so the SQL is driver-agnostic (MariaDB prod, SQLite tests).
 *
 * Risk definitions (evaluated in this order, per spec):
 *   churn_risk — days_since_last > churn_threshold AND baseline_n > 0
 *   ramping_up — normalized recent rate > 1.5 × normalized baseline rate,
 *                i.e. (recent_n / rw) > 1.5 (baseline_n / bw), evaluated
 *                integer-exactly as 2·recent_n·bw > 3·baseline_n·rw (also
 *                catches baseline 0 → any recent activity ramps)
 *   stable     — everything else
 *
 * Users with zero completed debates as a debater are excluded entirely —
 * there is nothing to measure.
 */
class EngagementChurnService
{
    public function __construct(private StatsCache $cache) {}

    /**
     * @param array{recent_window_days:int, baseline_window_days:int, churn_threshold_days:int, risk_filter:string, page:int, per_page:int} $p
     */
    public function compute(array $p): array
    {
        $key = implode(':', [
            "rw{$p['recent_window_days']}", "bw{$p['baseline_window_days']}",
            "ct{$p['churn_threshold_days']}", $p['risk_filter'],
            "p{$p['page']}", "pp{$p['per_page']}",
        ]);

        return $this->cache->remember('engagement_churn', $key, fn () => $this->build($p));
    }

    /** Export variant — same query, every matching row, no pagination. */
    public function computeAll(array $p): array
    {
        $key = implode(':', [
            "rw{$p['recent_window_days']}", "bw{$p['baseline_window_days']}",
            "ct{$p['churn_threshold_days']}", $p['risk_filter'], 'all',
        ]);

        return $this->cache->remember('engagement_churn', $key, function () use ($p) {
            $now = Carbon::now();
            $rows = $this->classifiedQuery($p, $now)->get();

            return [
                'stat'         => 'engagement_churn',
                'generated_at' => $now->toIso8601String(),
                'entries'      => $this->shapeEntries($rows, $now),
            ];
        });
    }

    private function build(array $p): array
    {
        $now = Carbon::now();
        $q = $this->classifiedQuery($p, $now);

        $total = DB::query()->fromSub($q, 'c')->count();
        $rows = $q->forPage($p['page'], $p['per_page'])->get();

        return [
            'stat'         => 'engagement_churn',
            'generated_at' => $now->toIso8601String(),
            'entries'      => $this->shapeEntries($rows, $now),
            'meta'         => [
                'page'     => $p['page'],
                'per_page' => $p['per_page'],
                'total'    => $total,
            ],
        ];
    }

    private function classifiedQuery(array $p, CarbonInterface $now): Builder
    {
        $recentStart = $now->copy()->subDays((int) $p['recent_window_days']);
        $baselineStart = $recentStart->copy()->subDays((int) $p['baseline_window_days']);
        $churnCutoff = $now->copy()->subDays((int) $p['churn_threshold_days']);

        $perUser = DB::table('debate_participants as dp')
            ->join('debates as d', 'd.id', '=', 'dp.debate_id')
            ->join('debate_results as dr', 'dr.debate_id', '=', 'd.id')
            ->where('dp.role', 'debater')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at')
            ->groupBy('dp.user_id')
            ->selectRaw(
                'dp.user_id,
                 MAX(d.scheduled_at) as last_at,
                 SUM(CASE WHEN d.scheduled_at >= ? THEN 1 ELSE 0 END) as recent_n,
                 SUM(CASE WHEN d.scheduled_at >= ? AND d.scheduled_at < ? THEN 1 ELSE 0 END) as baseline_n',
                [$recentStart, $baselineStart, $recentStart]
            );

        $classified = DB::query()
            ->fromSub($perUser, 't')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->selectRaw(
                "t.user_id, u.name, t.last_at, t.recent_n, t.baseline_n,
                 CASE
                     WHEN t.last_at < ? AND t.baseline_n > 0 THEN 'churn_risk'
                     WHEN (2 * t.recent_n * ?) > (3 * t.baseline_n * ?) THEN 'ramping_up'
                     ELSE 'stable'
                 END as risk",
                [$churnCutoff, (int) $p['baseline_window_days'], (int) $p['recent_window_days']]
            );

        $q = DB::query()->fromSub($classified, 'c');

        if ($p['risk_filter'] !== 'all') {
            $q->where('c.risk', $p['risk_filter']);
        }

        // Most urgent first: churn risks (longest-quiet first), then ramps.
        return $q->orderByRaw("CASE c.risk WHEN 'churn_risk' THEN 0 WHEN 'ramping_up' THEN 1 ELSE 2 END")
            ->orderBy('c.last_at')
            ->orderBy('c.user_id');
    }

    private function shapeEntries($rows, CarbonInterface $now): array
    {
        return collect($rows)->map(fn ($r) => [
            'user_id'                => (int) $r->user_id,
            'name'                   => (string) $r->name,
            'risk'                   => (string) $r->risk,
            'days_since_last_debate' => (int) floor(Carbon::parse($r->last_at)->diffInDays($now)),
            'recent_n_debates'       => (int) $r->recent_n,
            'baseline_n_debates'     => (int) $r->baseline_n,
        ])->values()->all();
    }
}
