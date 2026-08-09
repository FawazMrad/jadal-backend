<?php

namespace App\Services\Stats;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\MotionFramework;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * V2 §3 — no team-scoped equivalent of the debater stats API existed before
 * this. Deliberately scoped to single scalars (not the debater API's full
 * bucketed/series chart shape) since the only consumers are the leaderboard
 * (one all-time number per team) and the coach team-summary (one number per
 * team, then averaged across a coach's teams) — neither needs a chart.
 *
 * The improvement-index math (slope/consistency/bands) is a deliberate,
 * self-contained duplicate of DebaterStatsService's algorithm, using the same
 * `debate.stats.*` config constants, so the two indices stay comparable.
 */
class TeamStatsService
{
    public function winRate(Team $team, StatsFilter $f): ?float
    {
        $rows = $this->participationRows($team, $f);

        return $rows->isEmpty() ? null : round($rows->avg('won'), 4);
    }

    public function avgScore(Team $team, StatsFilter $f): ?float
    {
        $rows = $this->participationRows($team, $f)->filter(fn (TeamParticipationRow $r) => $r->avgScore !== null);

        return $rows->isEmpty() ? null : round($rows->avg('avgScore'), 4);
    }

    public function improvement(Team $team, StatsFilter $f): ?float
    {
        $rows = $this->participationRows($team, $f);
        $granularity = $this->monthsSpan($rows) > (int) config('debate.stats.improvement_month_to_year_span', 24) ? 'year' : 'month';

        $grouped = $rows->isEmpty() ? collect() : $rows->groupBy(fn (TeamParticipationRow $r) => $r->date->format($granularity === 'year' ? 'Y' : 'Y-m'))->sortKeys();

        $scores = [];
        $wins   = [];
        foreach ($grouped as $bucketRows) {
            $scored = $bucketRows->filter(fn (TeamParticipationRow $r) => $r->avgScore !== null);
            if ($scored->isEmpty()) {
                continue;
            }
            $scores[] = $scored->avg('avgScore');
            $wins[]   = $bucketRows->avg('won');
        }

        if (count($scores) < (int) config('debate.stats.min_buckets_for_index', 3)) {
            return null;
        }

        [$min, $max] = [(float) config('debate.score_range.min', 0), (float) config('debate.score_range.max', 100)];
        $k = ($max - $min) / (float) config('debate.stats.spec_reference_span', 31);
        $consistencyDenom = (float) config('debate.stats.spec_consistency_denominator', 15.5) * $k;
        $scoreNormDiv = (float) config('debate.stats.spec_score_norm_divisor', 3) * $k;
        $winNormDiv = (float) config('debate.stats.spec_winrate_norm_divisor', 0.1);

        $slopeScore = $this->slope($scores);
        $slopeWin = $this->slope($wins);
        $sigma = $this->stddev($scores);
        $consistency = max(0, min(1, 1 - $sigma / $consistencyDenom));

        $scoreNorm = max(-50, min(50, $slopeScore / $scoreNormDiv * 50));
        $winNorm = max(-50, min(50, $slopeWin / $winNormDiv * 50));
        $consNorm = ($consistency - 0.5) * 100;

        return round(0.5 * $scoreNorm + 0.4 * $winNorm + 0.1 * $consNorm, 4);
    }

    /** @return Collection<int, TeamParticipationRow> */
    public function participationRows(Team $team, StatsFilter $f): Collection
    {
        $debates = Debate::where(function ($q) use ($team) {
            $q->where('proposition_team_id', $team->id)->orWhere('opposition_team_id', $team->id);
        })
            ->where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->when($f->fromDate(), fn ($q, $from) => $q->where('scheduled_at', '>=', $from))
            ->when($f->toDateEnd(), fn ($q, $to) => $q->where('scheduled_at', '<=', $to))
            ->with(['result', 'motion.frameworks'])
            ->get();

        $rows = collect();
        foreach ($debates as $debate) {
            $result = $debate->result;
            if (! $result) {
                continue;
            }

            $side = (int) $debate->proposition_team_id === (int) $team->id ? 'proposition' : 'opposition';
            $won = $result->winning_side === 'draw' ? 0.5 : ($result->winning_side === $side ? 1.0 : 0.0);

            $memberIds = DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $team->id)
                ->where('role', 'debater')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $stages = $result->scores['stages'] ?? [];
            $scores = [];
            $speeches = [];
            foreach ($stages as $stage) {
                $userId = (int) ($stage['user_id'] ?? 0);
                $order  = (int) ($stage['stage_order'] ?? 0);

                if (! in_array($userId, $memberIds, true) || ! isset($stage['score'])) {
                    continue;
                }

                // Main-stage-only mean — the legacy scalar the leaderboard and
                // coach summary publish. Must not change.
                if ($order <= 6) {
                    $scores[] = (float) $stage['score'];
                }

                if (($code = PositionCodes::codeForStageOrder($order)) !== null) {
                    $speeches[] = [
                        'user_id'  => $userId,
                        'code'     => $code,
                        'score'    => (float) $stage['score'],
                        'is_reply' => PositionCodes::isReplyOrder($order),
                    ];
                }
            }

            $frameworks = $debate->motion?->frameworks ?? collect();

            $rows->push(new TeamParticipationRow(
                debateId: (int) $debate->id,
                date: Carbon::parse($debate->scheduled_at),
                won: $won,
                avgScore: empty($scores) ? null : array_sum($scores) / count($scores),
                frameworkIds: $frameworks->pluck('id')->map(fn ($i) => (int) $i)->all(),
                speeches: $speeches,
                side: $side,
                frameworkLabels: $frameworks->pluck('name', 'id')->all(),
            ));
        }

        // Scalar filters, mirroring DebaterStatsService: framework = ANY match,
        // position = the team fielded ≥1 speaker in a matching slot. A position
        // filter is effectively a side filter at team level (a proposition team
        // never occupies O1) — meaningful, but not the same question it answers
        // for an individual debater. Called out in the API reply.
        $slotSet = $f->positionSlots();

        return $rows->filter(function (TeamParticipationRow $row) use ($f, $slotSet) {
            if (! empty($f->frameworks) && empty(array_intersect($row->frameworkIds, $f->frameworks))) {
                return false;
            }
            if ($slotSet !== null && empty(array_intersect($row->positionsHeld(), $slotSet))) {
                return false;
            }

            return true;
        })->values();
    }

    // ── MF_FU §3.2 — bucketed shapes, byte-compatible with the debater API ─────

    /** Bucketed win rate — same envelope DebaterStatsService::winRate returns. */
    public function winRateStat(Collection $rows, StatsFilter $f): array
    {
        $buckets = $this->shapeSeriesBuckets($rows, $f, function (Collection $sel) {
            if ($sel->isEmpty()) {
                return null;
            }

            // Draws count as half a win, consistent with the scalar winRate().
            return ['value' => round($sel->avg('won'), 4), 'n_debates' => $sel->count()];
        });

        return [
            'stat'             => 'win_rate',
            'grouping'         => $this->groupingLabel($f->groupBy),
            'series_dimension' => $f->effectiveSeries(),
            'buckets'          => $buckets,
            'total_n_debates'  => $rows->pluck('debateId')->unique()->count(),
        ];
    }

    /** Bucketed average score — same envelope DebaterStatsService::avgScore returns. */
    public function avgScoreStat(Collection $rows, StatsFilter $f): array
    {
        $buckets = $this->shapeSeriesBuckets($rows, $f, function (Collection $sel, ?array $slots) {
            $vals = $sel->map(fn (TeamParticipationRow $r) => $r->normalizedScore($slots))
                ->filter(fn ($v) => $v !== null)
                ->values();

            return $vals->isEmpty()
                ? null
                : ['value' => round($vals->avg(), 4), 'n_debates' => $vals->count()];
        });

        return [
            'stat'             => 'avg_score',
            'grouping'         => $this->groupingLabel($f->groupBy),
            'series_dimension' => $f->effectiveSeries(),
            'buckets'          => $buckets,
            'total_n_debates'  => $rows->pluck('debateId')->unique()->count(),
        ];
    }

    /** Improvement index in the ImprovementStat envelope (index + band + components). */
    public function improvementStat(Collection $rows, StatsFilter $f): array
    {
        $slots       = $f->positionSlots();
        $total       = $rows->pluck('debateId')->unique()->count();
        $granularity = $this->monthsSpan($rows) > (int) config('debate.stats.improvement_month_to_year_span', 24)
            ? 'yearly' : 'monthly';

        $grouped = $rows->isEmpty()
            ? collect()
            : $rows->groupBy(fn (TeamParticipationRow $r) => $r->date->format($granularity === 'yearly' ? 'Y' : 'Y-m'))->sortKeys();

        $bucketList = [];
        $bScores    = [];
        $bWin       = [];
        foreach ($grouped as $label => $bucketRows) {
            $vals = $bucketRows->map(fn (TeamParticipationRow $r) => $r->normalizedScore($slots))
                ->filter(fn ($v) => $v !== null)->values();
            if ($vals->isEmpty()) {
                continue;
            }
            $score   = $vals->avg();
            $winRate = $bucketRows->avg('won');

            $bScores[] = $score;
            $bWin[]    = $winRate;
            $bucketList[] = [
                'label'     => $label,
                'avg_score' => round($score, 4),
                'win_rate'  => round($winRate, 4),
                'n_debates' => $bucketRows->count(),
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

        [$min, $max]      = [(float) config('debate.score_range.min', 0), (float) config('debate.score_range.max', 100)];
        $k                = ($max - $min) / (float) config('debate.stats.spec_reference_span', 31);
        $consistencyDenom = (float) config('debate.stats.spec_consistency_denominator', 15.5) * $k;
        $scoreNormDiv     = (float) config('debate.stats.spec_score_norm_divisor', 3) * $k;
        $winNormDiv       = (float) config('debate.stats.spec_winrate_norm_divisor', 0.1);

        $slopeScore = $this->slope($bScores);
        $slopeWin   = $this->slope($bWin);

        $allNormalized = $rows->map(fn (TeamParticipationRow $r) => $r->normalizedScore($slots))
            ->filter(fn ($v) => $v !== null)->values()->all();
        $sigma       = $this->stddev($allNormalized);
        $consistency = max(0, min(1, 1 - $sigma / $consistencyDenom));

        $scoreNorm = max(-50, min(50, $slopeScore / $scoreNormDiv * 50));
        $winNorm   = max(-50, min(50, $slopeWin / $winNormDiv * 50));
        $consNorm  = ($consistency - 0.5) * 100;
        $index     = round(0.5 * $scoreNorm + 0.4 * $winNorm + 0.1 * $consNorm, 4);

        // Key names, band thresholds and the absence of `reason` on this path all
        // mirror DebaterStatsService::improvement exactly — the client reuses one
        // parser for both, so any divergence here is a bug.
        return [
            'stat'        => 'improvement',
            'granularity' => $granularity,
            'index'       => $index,
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

    // ── MF_FU §4 — line-up (combination) analysis ──────────────────────────────

    /**
     * Which set of speakers actually performs best.
     *
     * A combination is the ORDER-INSENSITIVE set of members who were scored in
     * one debate, so re-arranging the same three people across P1/P2/P3 does not
     * fragment the sample. Sizes are not assumed to be 3 — whatever the format
     * fielded is what gets grouped, and each combination carries its own `size`.
     *
     * @param  Collection<int, TeamParticipationRow>  $rows
     */
    public function combinations(Collection $rows, string $metric, int $minDebates, int $limit): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $ids = $row->speakerIds();
            if (empty($ids)) {
                continue;
            }
            $groups[implode('-', $ids)][] = $row;
        }

        $all = [];
        foreach ($groups as $key => $groupRows) {
            $ids    = array_map('intval', explode('-', $key));
            $scores = [];
            foreach ($groupRows as $row) {
                // The line-up's score in one debate = the mean of its own
                // members' means, so a 3-speaker debate is not outweighed by a
                // 4-speaker one and a member who spoke twice counts once.
                $perMember = array_values(array_filter(
                    array_map(fn (int $id) => $row->scoreForMember($id), $ids),
                    fn ($v) => $v !== null
                ));
                if (! empty($perMember)) {
                    $scores[] = array_sum($perMember) / count($perMember);
                }
            }

            $n    = count($groupRows);
            $wins = 0.0;
            foreach ($groupRows as $row) {
                $wins += $row->won;
            }

            $all[] = [
                'key'            => $key,
                'member_ids'     => $ids,
                'size'           => count($ids),
                'n_debates'      => $n,
                'wins'           => round($wins, 2),
                'win_rate'       => round($wins / $n, 4),
                'avg_score'      => empty($scores) ? null : round(array_sum($scores) / count($scores), 4),
                'last_debate_at' => collect($groupRows)->max(fn (TeamParticipationRow $r) => $r->date->timestamp),
            ];
        }

        $distinct = count($all);

        $eligible = array_values(array_filter($all, fn (array $c) => $c['n_debates'] >= $minDebates));

        usort($eligible, function (array $a, array $b) use ($metric) {
            // Null avg_score sorts last rather than as zero.
            $av = $metric === 'avg_score' ? $a['avg_score'] : $a['win_rate'];
            $bv = $metric === 'avg_score' ? $b['avg_score'] : $b['win_rate'];
            if ($av === null xor $bv === null) {
                return $av === null ? 1 : -1;
            }
            if ($av !== $bv) {
                return $bv <=> $av;
            }

            // More evidence wins ties, then the more recent line-up.
            return [$b['n_debates'], $b['last_debate_at']] <=> [$a['n_debates'], $a['last_debate_at']];
        });

        return [
            'distinct_combinations'    => $distinct,
            'total_debates_considered' => $rows->pluck('debateId')->unique()->count(),
            'combinations'             => array_slice($eligible, 0, $limit),
        ];
    }

    /** Team-wide figures over the same filtered window, for "+X vs the team average". */
    public function baseline(Collection $rows): array
    {
        $scored = $rows->map(fn (TeamParticipationRow $r) => $r->normalizedScore(null))
            ->filter(fn ($v) => $v !== null)->values();

        return [
            'n_debates' => $rows->pluck('debateId')->unique()->count(),
            'win_rate'  => $rows->isEmpty() ? null : round($rows->avg('won'), 4),
            'avg_score' => $scored->isEmpty() ? null : round($scored->avg(), 4),
        ];
    }

    // ── Shared bucketing (mirrors DebaterStatsService) ─────────────────────────

    private function shapeSeriesBuckets(Collection $rows, StatsFilter $f, callable $valueFn): array
    {
        $plan = $this->seriesPlan($f);

        if ($f->groupBy === 'none') {
            $buckets = $rows->isEmpty() ? [] : ['all' => $rows];
        } else {
            $fmt = $f->groupBy === 'year' ? 'Y' : 'Y-m';
            $buckets = $rows->groupBy(fn (TeamParticipationRow $r) => $r->date->format($fmt))->sortKeys()->all();
        }

        $out = [];
        foreach ($buckets as $label => $bucketRows) {
            $series = [];
            foreach ($plan as $entry) {
                $sel   = $bucketRows->filter($entry['match'])->values();
                $value = $valueFn($sel, $entry['slots']);
                if ($value === null) {
                    continue;
                }
                $series[] = array_merge(['key' => $entry['key'], 'label' => $entry['label']], $value);
            }
            if (! empty($series)) {
                $out[] = ['label' => (string) $label, 'series' => $series];
            }
        }

        return $out;
    }

    /** @return array<int, array{key:string, label:string, slots:?array, match:callable}> */
    private function seriesPlan(StatsFilter $f): array
    {
        $slots = $f->positionSlots();

        switch ($f->effectiveSeries()) {
            case 'frameworks':
                $names = MotionFramework::whereIn('id', $f->frameworks)->pluck('name', 'id')->all();

                return array_map(fn ($fid) => [
                    'key'   => (string) $fid,
                    'label' => $names[$fid] ?? (string) $fid,
                    'match' => fn (TeamParticipationRow $r) => in_array((int) $fid, $r->frameworkIds, true),
                    'slots' => $slots,
                ], $f->frameworks);

            case 'positions':
                return array_map(fn ($code) => [
                    'key'   => $code,
                    'label' => $code,
                    'match' => fn (TeamParticipationRow $r) => ! empty(array_intersect($r->positionsHeld(), PositionCodes::expand($code))),
                    'slots' => PositionCodes::expand($code),
                ], $f->positions);

            default:
                return [[
                    'key'   => 'all',
                    'label' => 'All',
                    'match' => fn (TeamParticipationRow $r) => true,
                    'slots' => $slots,
                ]];
        }
    }

    private function groupingLabel(string $groupBy): string
    {
        return match ($groupBy) {
            'year'  => 'by_year',
            'month' => 'by_month',
            default => 'none',
        };
    }

    /** Same thresholds and labels as DebaterStatsService::band. */
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

    private function monthsSpan(Collection $rows): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }
        $dates = $rows->map(fn (TeamParticipationRow $r) => $r->date);

        return (int) $dates->min()->diffInMonths($dates->max());
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

        return sqrt($var / $n);
    }
}
