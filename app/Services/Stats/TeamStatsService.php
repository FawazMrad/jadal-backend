<?php

namespace App\Services\Stats;

use App\Models\Debate;
use App\Models\DebateParticipant;
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
            ->with('result')
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
            foreach ($stages as $stage) {
                if (! in_array((int) ($stage['user_id'] ?? 0), $memberIds, true)) {
                    continue;
                }
                if ((int) ($stage['stage_order'] ?? 0) > 6 || ! isset($stage['score'])) {
                    continue;
                }
                $scores[] = (float) $stage['score'];
            }

            $rows->push(new TeamParticipationRow(
                debateId: (int) $debate->id,
                date: Carbon::parse($debate->scheduled_at),
                won: $won,
                avgScore: empty($scores) ? null : array_sum($scores) / count($scores),
            ));
        }

        return $rows->values();
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
