<?php

namespace App\Services\Stats;

use App\Models\DebateParticipant;
use App\Models\Feedbacks;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * How the room rated a judge's judging.
 *
 * Source rows are `feedbacks` with type = 'rating_judgement'. Those carry a
 * mandatory `to_user_id` that StoreFeedbackRequest validates to be a judge OF
 * THAT DEBATE, so a rating already belongs to one named judge — there is no
 * panel-attribution problem to solve, and multi-judge debates need no special
 * casing.
 *
 * `rating_debate` rows are excluded: they rate the debate itself and carry no
 * to_user_id at all.
 *
 * Nothing identifying is emitted. Raters and their free-text notes stay in the
 * table; only counts and means come out.
 */
class JudgeRatingStatsService
{
    public function ratings(User $judge, StatsFilter $f): array
    {
        $ratings = $this->ratingRows($judge, $f);

        $debatesJudged = $this->debatesJudged($judge, $f);
        $debatesRated  = $ratings->pluck('debate_id')->unique()->count();

        $buckets = [];
        foreach ($this->bucketize($ratings, $f) as $label => $bucketRows) {
            $buckets[] = [
                'label'         => (string) $label,
                'avg_rating'    => $bucketRows->isEmpty() ? null : round($bucketRows->avg('rating'), 2),
                'ratings_count' => $bucketRows->count(),
                'debates_rated' => $bucketRows->pluck('debate_id')->unique()->count(),
            ];
        }

        [$min, $max] = $this->scale();

        return [
            'stat'         => 'judge_ratings',
            'judge_id'     => (int) $judge->id,
            'grouping'     => match ($f->groupBy) {
                'year'  => 'by_year',
                'month' => 'by_month',
                default => 'none',
            },
            'rating_scale' => ['min' => $min, 'max' => $max],
            'totals'       => [
                'avg_rating'    => $ratings->isEmpty() ? null : round($ratings->avg('rating'), 2),
                'ratings_count' => $ratings->count(),
                'debates_rated' => $debatesRated,
                'debates_judged' => $debatesJudged,
                // Share of the judge's completed debates that produced at least
                // one rating. Null rather than a divide-by-zero 0.0 when they
                // have judged nothing in the window.
                'coverage'      => $debatesJudged === 0 ? null : round($debatesRated / $debatesJudged, 4),
                'distribution'  => $this->distribution($ratings, $min, $max),
            ],
            'buckets'      => $buckets,
            'peer_average' => $this->peerAverage($judge, $f),
            'reason'       => $ratings->isEmpty()
                ? ($debatesJudged === 0 ? 'no_debates_judged' : 'no_ratings_received')
                : null,
        ];
    }

    /** @return Collection<int, array{debate_id:int, rating:float, date:Carbon}> */
    private function ratingRows(User $judge, StatsFilter $f): Collection
    {
        return $this->baseQuery($f)
            ->where('to_user_id', $judge->id)
            ->get()
            ->map(fn (Feedbacks $r) => $this->toRow($r))
            ->filter()
            ->values();
    }

    /**
     * Where this judge stands against everyone else. Deliberately EXCLUDES the
     * judge's own ratings, so a judge with most of the volume is not largely
     * compared against themselves. Null when nobody else has been rated in the
     * window — with one rated judge there is no peer group, and returning their
     * own average as the "peer" figure would read as "exactly average".
     */
    private function peerAverage(User $judge, StatsFilter $f): ?float
    {
        $peers = $this->baseQuery($f)
            ->whereNotNull('to_user_id')
            ->where('to_user_id', '!=', $judge->id)
            ->get()
            ->map(fn (Feedbacks $r) => $this->toRow($r))
            ->filter();

        return $peers->isEmpty() ? null : round($peers->avg('rating'), 2);
    }

    private function baseQuery(StatsFilter $f)
    {
        return Feedbacks::query()
            ->where('type', 'rating_judgement')
            ->whereHas('debate', function ($q) use ($f) {
                $q->whereNotNull('result_revealed_at');
                if (! empty($f->frameworks)) {
                    $q->whereHas('motion.frameworks', fn ($fw) => $fw->whereIn('motion_frameworks.id', $f->frameworks));
                }
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with('debate:id,scheduled_at');
    }

    /** @return array{debate_id:int, rating:float, date:Carbon}|null */
    private function toRow(Feedbacks $r): ?array
    {
        $rating = $r->scores['rating'] ?? null;
        if (! is_numeric($rating) || ! $r->debate) {
            return null;
        }

        return [
            'debate_id' => (int) $r->debate_id,
            'rating'    => (float) $rating,
            'date'      => Carbon::parse($r->debate->scheduled_at),
        ];
    }

    /**
     * Debates this judge ACTUALLY judged — an approved judge participant on a
     * completed debate. A judge who merely registered and was never selected
     * has not judged anything, and counting those would depress `coverage`
     * with debates nobody could have rated them for.
     */
    private function debatesJudged(User $judge, StatsFilter $f): int
    {
        return DebateParticipant::where('user_id', $judge->id)
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->whereHas('debate', function ($q) use ($f) {
                $q->where('status', 'completed')->whereNotNull('result_revealed_at');
                if (! empty($f->frameworks)) {
                    $q->whereHas('motion.frameworks', fn ($fw) => $fw->whereIn('motion_frameworks.id', $f->frameworks));
                }
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->distinct('debate_id')
            ->count('debate_id');
    }

    /** Every point on the scale is present, including the ones nobody picked. */
    private function distribution(Collection $ratings, int $min, int $max): array
    {
        $out = [];
        for ($i = $min; $i <= $max; $i++) {
            $out[(string) $i] = 0;
        }
        foreach ($ratings as $r) {
            $key = (string) (int) round($r['rating']);
            if (array_key_exists($key, $out)) {
                $out[$key]++;
            }
        }

        return $out;
    }

    /** @return array{0:int, 1:int} */
    private function scale(): array
    {
        return [
            (int) config('debate.rating_scale.min', 1),
            (int) config('debate.rating_scale.max', 5),
        ];
    }

    /** @return array<string, Collection> */
    private function bucketize(Collection $ratings, StatsFilter $f): array
    {
        if ($f->groupBy === 'none') {
            return $ratings->isEmpty() ? [] : ['all' => $ratings];
        }

        $fmt     = $f->groupBy === 'year' ? 'Y' : 'Y-m';
        $grouped = $ratings->groupBy(fn (array $r) => $r['date']->format($fmt))->sortKeys();

        // Same zero-fill contract as the activity endpoint — a month with
        // no ratings is a flat point on the trend, not a gap.
        $start = $f->fromDate()  ?? ($ratings->isEmpty() ? null : $ratings->min(fn ($r) => $r['date'])->copy());
        $end   = $f->toDateEnd() ?? ($ratings->isEmpty() ? null : $ratings->max(fn ($r) => $r['date'])->copy());

        if ($start === null || $end === null || $start->gt($end)) {
            return $grouped->all();
        }

        $out    = [];
        $cursor = $start->copy()->startOfMonth();
        $limit  = (int) config('debate.stats.max_zero_filled_buckets', 240);

        while ($cursor->lte($end) && count($out) < $limit) {
            $label       = $cursor->format($fmt);
            $out[$label] = $grouped->get($label, collect());
            $cursor      = $f->groupBy === 'year' ? $cursor->addYear() : $cursor->addMonth();
        }

        foreach ($grouped as $label => $bucket) {
            $out[$label] ??= $bucket;
        }

        ksort($out);

        return $out;
    }
}
