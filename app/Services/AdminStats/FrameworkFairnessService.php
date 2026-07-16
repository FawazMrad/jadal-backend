<?php

namespace App\Services\AdminStats;

use Illuminate\Support\Facades\DB;

/**
 * Stat 1 — Framework Fairness Index.
 *
 * Detects frameworks whose motions structurally favor one side. Pure SQL
 * aggregate over completed debates; the framework fan-out means one debate is
 * counted once per framework its motion carries (tag semantics — summing
 * n_debates across frameworks may legitimately exceed the platform total).
 *
 * Draws stay in n_debates but count as a win for neither side, so a
 * draw-heavy framework reads as "balanced" by construction:
 *   imbalance = |prop_win_rate - 0.5| * 2   (0 = fair, 1 = one-sided)
 */
class FrameworkFairnessService
{
    public function __construct(private StatsCache $cache) {}

    public function compute(AdminStatFilters $f, int $minNDebates): array
    {
        $key = $f->cacheKeyPart() . ":min{$minNDebates}";

        return $this->cache->remember('framework_fairness', $key, fn () => $this->build($f, $minNDebates));
    }

    private function build(AdminStatFilters $f, int $minNDebates): array
    {
        $q = DB::table('debates as d')
            ->join('debate_results as dr', 'dr.debate_id', '=', 'd.id')
            ->join('motion_framework_pivot as mfp', 'mfp.motion_id', '=', 'd.motion_id')
            ->join('motion_frameworks as mf', 'mf.id', '=', 'mfp.framework_id')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at');

        $f->applyWindow($q, 'd.scheduled_at');
        $f->applyFormats($q, 'd.format_id');

        $rows = $q->groupBy('mfp.framework_id', 'mf.name')
            ->selectRaw(
                "mfp.framework_id,
                 mf.name as label,
                 COUNT(*) as n_debates,
                 SUM(CASE WHEN dr.winning_side = 'proposition' THEN 1 ELSE 0 END) as prop_wins,
                 SUM(CASE WHEN dr.winning_side = 'opposition' THEN 1 ELSE 0 END) as opp_wins"
            )
            ->get();

        $frameworks = $rows->map(function ($r) use ($minNDebates) {
            $n = (int) $r->n_debates;
            $propRate = $n > 0 ? ((int) $r->prop_wins) / $n : 0.0;
            $oppRate = $n > 0 ? ((int) $r->opp_wins) / $n : 0.0;
            $imbalance = abs($propRate - 0.5) * 2;

            return [
                'framework_id'    => (int) $r->framework_id,
                'label'           => (string) $r->label,
                'prop_win_rate'   => round($propRate, 4),
                'opp_win_rate'    => round($oppRate, 4),
                'imbalance_score' => round($imbalance, 4),
                'flagged'         => $imbalance > 0.3 && $n >= $minNDebates,
                'n_debates'       => $n,
            ];
        })
            // Most problematic first — flagged, then by imbalance, then by volume.
            ->sortBy([
                ['flagged', 'desc'],
                ['imbalance_score', 'desc'],
                ['n_debates', 'desc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'stat'          => 'framework_fairness',
            'min_n_debates' => $minNDebates,
            'frameworks'    => $frameworks,
        ];
    }
}
