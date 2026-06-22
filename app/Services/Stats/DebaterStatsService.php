<?php

namespace App\Services\Stats;

use App\Models\DebateParticipant;
use App\Models\MotionFramework;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes every debater statistic over a reusable "participation row" view.
 *
 * To add a 6th stat: build the rows once via participationRows() and add a new
 * shaper method that aggregates over them — no new query plumbing needed.
 */
class DebaterStatsService
{
    // ── Participation-row view (the performance-critical, reused piece) ─────────

    /**
     * @return Collection<int, ParticipationRow>
     */
    public function participationRows(User $debater, StatsFilter $f): Collection
    {
        $participants = DebateParticipant::query()
            ->where('user_id', $debater->id)
            ->where('role', 'debater')
            ->whereHas('debate', function ($q) use ($f) {
                $q->where('status', 'completed')
                    ->whereNotNull('result_revealed_at')
                    ->whereHas('result', fn ($r) => $r->whereIn('winning_side', ['proposition', 'opposition']));

                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with(['debate.result', 'debate.motion.frameworks'])
            ->get();

        $rows = collect();

        foreach ($participants as $p) {
            $debate = $p->debate;
            $result = $debate?->result;
            $stagesData = $result?->scores['stages'] ?? null;

            // Completed-debate guarantee: chair-submitted stage scores must exist.
            if (! is_array($stagesData) || empty($stagesData)) {
                continue;
            }

            // This debater's scored stages.
            $myStages = [];
            foreach ($stagesData as $st) {
                if ((int) ($st['user_id'] ?? 0) !== (int) $debater->id) {
                    continue;
                }
                $order = (int) ($st['stage_order'] ?? 0);
                $code = PositionCodes::codeForStageOrder($order);
                if ($code === null || ! isset($st['score'])) {
                    continue;
                }
                $myStages[] = [
                    'order'    => $order,
                    'code'     => $code,
                    'is_reply' => PositionCodes::isReplyOrder($order),
                    'score'    => (float) $st['score'],
                ];
            }

            if (empty($myStages)) {
                continue;
            }

            // Best speaker = highest main (stages 1-6) score in the whole debate.
            $mainScores = [];
            foreach ($stagesData as $st) {
                if ((int) ($st['stage_order'] ?? 0) <= 6 && isset($st['score'])) {
                    $mainScores[] = (float) $st['score'];
                }
            }
            $maxMain = empty($mainScores) ? null : max($mainScores);
            $myMain = null;
            foreach ($myStages as $s) {
                if (! $s['is_reply']) {
                    $myMain = $myMain === null ? $s['score'] : max($myMain, $s['score']);
                }
            }
            $isBest = $myMain !== null && $maxMain !== null && abs($myMain - $maxMain) < 1e-9;

            $frameworks = $debate->motion?->frameworks ?? collect();

            $rows->push(new ParticipationRow(
                debateId:       (int) $debate->id,
                date:           Carbon::parse($debate->scheduled_at),
                frameworkIds:   $frameworks->pluck('id')->map(fn ($i) => (int) $i)->all(),
                side:           (string) $p->side,
                winningSide:    (string) $result->winning_side,
                won:            (string) $p->side === (string) $result->winning_side,
                teamKey:        $p->team_id ? (string) $p->team_id : 'RANDOM',
                teamId:         $p->team_id ? (int) $p->team_id : null,
                stages:         $myStages,
                isBestSpeaker:  $isBest,
                motionText:     (string) ($debate->motion?->text ?? ''),
                frameworkLabels: $frameworks->pluck('name', 'id')->all(),
            ));
        }

        // Scalar filters (framework = ANY match; team; position = spoke ≥1 match).
        $slotSet = $f->positionSlots();

        return $rows->filter(function (ParticipationRow $row) use ($f, $slotSet) {
            if (! empty($f->frameworks) && empty(array_intersect($row->frameworkIds, $f->frameworks))) {
                return false;
            }
            if (! empty($f->teams) && ! in_array($row->teamKey, $f->teams, true)) {
                return false;
            }
            if ($slotSet !== null && empty(array_intersect($row->positionsHeld(), $slotSet))) {
                return false;
            }

            return true;
        })->values();
    }

    // ── Stat 1: win rate ───────────────────────────────────────────────────────

    public function winRate(Collection $rows, StatsFilter $f): array
    {
        $buckets = $this->shapeSeriesBuckets($rows, $f, function (Collection $sel, ?array $slots) {
            $n = $sel->count();
            if ($n === 0) {
                return null;
            }
            $wins = $sel->filter(fn (ParticipationRow $r) => $r->won)->count();

            return ['value' => round($wins / $n, 4), 'n_debates' => $n];
        });

        return [
            'stat'             => 'win_rate',
            'grouping'         => $this->groupingLabel($f->groupBy),
            'series_dimension' => $f->effectiveSeries(),
            'buckets'          => $buckets,
            'total_n_debates'  => $this->distinctDebates($rows),
        ];
    }

    // ── Stat 2: average speech score ───────────────────────────────────────────

    public function avgScore(Collection $rows, StatsFilter $f): array
    {
        $buckets = $this->shapeSeriesBuckets($rows, $f, function (Collection $sel, ?array $slots) {
            $vals = $sel->map(fn (ParticipationRow $r) => $r->normalizedScore($slots))
                ->filter(fn ($v) => $v !== null)
                ->values();
            if ($vals->isEmpty()) {
                return null;
            }

            return ['value' => round($vals->avg(), 4), 'n_debates' => $vals->count()];
        });

        return [
            'stat'             => 'avg_score',
            'grouping'         => $this->groupingLabel($f->groupBy),
            'series_dimension' => $f->effectiveSeries(),
            'buckets'          => $buckets,
            'total_n_debates'  => $this->distinctDebates($rows),
        ];
    }

    // ── Stat 2.5: best speaker count ───────────────────────────────────────────

    public function bestSpeaker(Collection $rows, StatsFilter $f): array
    {
        $buckets = $this->shapeSeriesBuckets($rows, $f, function (Collection $sel, ?array $slots) {
            $n = $sel->count();
            if ($n === 0) {
                return null;
            }
            $count = $sel->filter(fn (ParticipationRow $r) => $r->isBestSpeaker)->count();

            return ['count' => $count, 'rate' => round($count / $n, 4), 'n_debates' => $n];
        });

        return [
            'stat'             => 'best_speaker',
            'grouping'         => $this->groupingLabel($f->groupBy),
            'series_dimension' => $f->effectiveSeries(),
            'buckets'          => $buckets,
            'total_n_debates'  => $this->distinctDebates($rows),
        ];
    }

    // ── Stat 3: score ranking ──────────────────────────────────────────────────

    public function scoreRanking(Collection $rows, StatsFilter $f): array
    {
        $slots = $f->positionSlots();

        $scored = $rows->map(function (ParticipationRow $r) use ($slots) {
            $score = $r->normalizedScore($slots);

            return $score === null ? null : ['row' => $r, 'score' => $score];
        })->filter()->values();

        $sorted = match ($f->rankingMode) {
            'bottom'   => $scored->sortBy([['score', 'asc'], ['row.date', 'desc']]),
            'latest'   => $scored->sortByDesc(fn ($e) => $e['row']->date->getTimestamp()),
            'earliest' => $scored->sortBy(fn ($e) => $e['row']->date->getTimestamp()),
            default    => $scored->sortBy([['score', 'desc'], ['row.date', 'desc']]), // top
        };

        $entries = $sorted->take($f->limit)->map(function ($e) {
            /** @var ParticipationRow $r */
            $r = $e['row'];
            $framework = null;
            if (! empty($r->frameworkIds)) {
                $fid = $r->frameworkIds[0];
                $framework = ['id' => $fid, 'label' => $r->frameworkLabels[$fid] ?? null];
            }

            $entry = [
                'debate_id'       => $r->debateId,
                'debate_date'     => $r->date->toDateString(),
                'motion_text'     => $r->motionText,
                'framework'       => $framework,
                'side'            => $r->side,
                'positions_held'  => $r->positionsHeld(),
                'team'            => ['id' => $r->teamId, 'label' => $r->teamId ? null : 'Random'],
                'normalized_score' => round($e['score'], 4),
                'raw_main_score'  => $r->mainScore(),
                'winning_side'    => $r->winningSide,
                'debate_won'      => $r->won,
            ];

            if (($reply = $r->replyScore()) !== null) {
                $entry['raw_reply_score'] = $reply;
            }

            return $entry;
        })->values()->all();

        return [
            'stat'                     => 'score_ranking',
            'ranking_mode'             => $f->rankingMode,
            'limit'                    => $f->limit,
            'total_qualifying_debates' => $scored->count(),
            'entries'                  => $entries,
        ];
    }

    // ── Stat 4: improvement index ──────────────────────────────────────────────

    public function improvement(Collection $rows, StatsFilter $f): array
    {
        $slots = $f->positionSlots();
        $total = $this->distinctDebates($rows);

        $granularity = $this->improvementGranularity($rows);
        $grouped = $this->bucketsOf($rows, $granularity === 'yearly' ? 'year' : 'month');

        $bucketList = [];
        $bScores = [];
        $bWin = [];
        foreach ($grouped as $label => $bucketRows) {
            $vals = $bucketRows->map(fn (ParticipationRow $r) => $r->normalizedScore($slots))
                ->filter(fn ($v) => $v !== null)->values();
            $n = $bucketRows->count();
            if ($vals->isEmpty()) {
                continue;
            }
            $score = $vals->avg();
            $winRate = $bucketRows->filter(fn (ParticipationRow $r) => $r->won)->count() / $n;

            $bScores[] = $score;
            $bWin[] = $winRate;
            $bucketList[] = [
                'label'     => $label,
                'avg_score' => round($score, 4),
                'win_rate'  => round($winRate, 4),
                'n_debates' => $n,
            ];
        }

        $minBuckets = (int) config('debate.stats.min_buckets_for_index', 3);

        if (count($bucketList) < $minBuckets) {
            return [
                'stat'            => 'improvement',
                'granularity'     => $granularity,
                'index'           => null,
                'reason'          => 'insufficient_history',
                'band'            => null,
                'components'      => null,
                'buckets'         => $bucketList,
                'total_n_debates' => $total,
            ];
        }

        [$min, $max] = $this->scoreRange();
        $span = $max - $min;
        $refSpan = (float) config('debate.stats.spec_reference_span', 31);
        $k = $span / $refSpan;
        $consistencyDenom = (float) config('debate.stats.spec_consistency_denominator', 15.5) * $k;
        $scoreNormDiv = (float) config('debate.stats.spec_score_norm_divisor', 3) * $k;
        $winNormDiv = (float) config('debate.stats.spec_winrate_norm_divisor', 0.1);

        $slopeScore = $this->slope($bScores);
        $slopeWin = $this->slope($bWin);

        $allNormalized = $rows->map(fn (ParticipationRow $r) => $r->normalizedScore($slots))
            ->filter(fn ($v) => $v !== null)->values()->all();
        $sigma = $this->stddev($allNormalized);
        $consistency = $this->clamp(1 - $sigma / $consistencyDenom, 0, 1);

        $scoreNorm = $this->clamp($slopeScore / $scoreNormDiv * 50, -50, 50);
        $winNorm = $this->clamp($slopeWin / $winNormDiv * 50, -50, 50);
        $consNorm = ($consistency - 0.5) * 100;

        $index = 0.5 * $scoreNorm + 0.4 * $winNorm + 0.1 * $consNorm;

        return [
            'stat'        => 'improvement',
            'granularity' => $granularity,
            'index'       => round($index, 4),
            'band'        => $this->band($index),
            'components'  => [
                'slope_score'      => round($slopeScore, 4),
                'slope_winrate'    => round($slopeWin, 4),
                'consistency'      => round($consistency, 4),
                'score_norm'       => round($scoreNorm, 4),
                'winrate_norm'     => round($winNorm, 4),
                'consistency_norm' => round($consNorm, 4),
            ],
            'buckets'         => $bucketList,
            'total_n_debates' => $total,
        ];
    }

    // ── Span helper (for the group_by=month guard) ─────────────────────────────

    public function monthsSpan(Collection $rows): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }
        $dates = $rows->map(fn (ParticipationRow $r) => $r->date);

        return (int) $dates->min()->diffInMonths($dates->max());
    }

    // ── Shared shaping/series/bucketing ────────────────────────────────────────

    /**
     * Builds the buckets[].series[] structure shared by stats 1, 2 and 2.5.
     * $valueFn(Collection $selectedRows, ?array $slots): ?array(extra fields).
     */
    private function shapeSeriesBuckets(Collection $rows, StatsFilter $f, callable $valueFn): array
    {
        $plan = $this->seriesPlan($f);
        $buckets = $this->bucketsOf($rows, $f->groupBy);

        $out = [];
        foreach ($buckets as $label => $bucketRows) {
            $series = [];
            foreach ($plan as $entry) {
                $sel = $bucketRows->filter($entry['match'])->values();
                $value = $valueFn($sel, $entry['slots']);
                if ($value === null) {
                    continue;
                }
                $series[] = array_merge(['key' => $entry['key'], 'label' => $entry['label']], $value);
            }
            if (! empty($series)) {
                $out[] = ['label' => $label, 'series' => $series];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{key:string, label:string, match:callable, slots:?array}>
     */
    private function seriesPlan(StatsFilter $f): array
    {
        $slots = $f->positionSlots();

        switch ($f->effectiveSeries()) {
            case 'frameworks':
                $names = MotionFramework::whereIn('id', $f->frameworks)->pluck('name', 'id')->all();

                return array_map(fn ($fid) => [
                    'key'   => (string) $fid,
                    'label' => $names[$fid] ?? (string) $fid,
                    'match' => fn (ParticipationRow $r) => in_array((int) $fid, $r->frameworkIds, true),
                    'slots' => $slots,
                ], $f->frameworks);

            case 'positions':
                return array_map(fn ($code) => [
                    'key'   => $code,
                    'label' => $code,
                    'match' => fn (ParticipationRow $r) => ! empty(array_intersect($r->positionsHeld(), PositionCodes::expand($code))),
                    'slots' => PositionCodes::expand($code),
                ], $f->positions);

            case 'teams':
                $numeric = array_values(array_filter($f->teams, fn ($t) => $t !== 'RANDOM'));
                $names = empty($numeric) ? [] : Team::whereIn('id', $numeric)->pluck('name', 'id')->all();

                return array_map(fn ($tkey) => [
                    'key'   => (string) $tkey,
                    'label' => $tkey === 'RANDOM' ? 'Random' : ($names[(int) $tkey] ?? (string) $tkey),
                    'match' => fn (ParticipationRow $r) => $r->teamKey === (string) $tkey,
                    'slots' => $slots,
                ], $f->teams);

            default:
                return [[
                    'key'   => 'all',
                    'label' => 'All',
                    'match' => fn (ParticipationRow $r) => true,
                    'slots' => $slots,
                ]];
        }
    }

    /**
     * @return array<string, Collection<int, ParticipationRow>>  ordered chronologically
     */
    private function bucketsOf(Collection $rows, string $groupBy): array
    {
        if ($groupBy === 'none') {
            return $rows->isEmpty() ? [] : ['all' => $rows];
        }

        $fmt = $groupBy === 'year' ? 'Y' : 'Y-m';
        $grouped = $rows->groupBy(fn (ParticipationRow $r) => $r->date->format($fmt));

        return $grouped->sortKeys()->all();
    }

    private function improvementGranularity(Collection $rows): string
    {
        $guard = (int) config('debate.stats.improvement_month_to_year_span', 24);

        return $this->monthsSpan($rows) > $guard ? 'yearly' : 'monthly';
    }

    private function groupingLabel(string $groupBy): string
    {
        return match ($groupBy) {
            'year'  => 'by_year',
            'month' => 'by_month',
            default => 'none',
        };
    }

    private function distinctDebates(Collection $rows): int
    {
        return $rows->pluck('debateId')->unique()->count();
    }

    private function band(float $index): string
    {
        return match (true) {
            $index >= 20  => 'strong_upward',
            $index >= 5   => 'improving',
            $index > -5   => 'stable',
            $index > -20  => 'regressing',
            default       => 'sharp_decline',
        };
    }

    // ── Math ───────────────────────────────────────────────────────────────────

    /** @return array{0: float, 1: float} [min, max] */
    private function scoreRange(): array
    {
        return [
            (float) config('debate.score_range.min', 0),
            (float) config('debate.score_range.max', 100),
        ];
    }

    private function slope(array $ys): float
    {
        $k = count($ys);
        if ($k < 2) {
            return 0.0;
        }
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($ys as $i => $y) {
            $sx += $i;
            $sy += $y;
            $sxy += $i * $y;
            $sxx += $i * $i;
        }
        $den = $k * $sxx - $sx * $sx;

        return $den == 0.0 ? 0.0 : ($k * $sxy - $sx * $sy) / $den;
    }

    private function stddev(array $vals): float
    {
        $n = count($vals);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($vals) / $n;
        $var = 0.0;
        foreach ($vals as $v) {
            $var += ($v - $mean) ** 2;
        }

        return sqrt($var / $n); // population standard deviation
    }

    private function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }
}
