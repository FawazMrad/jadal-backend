<?php

namespace App\Services\Stats;

use App\Models\DebateParticipant;
use App\Models\DebateViewer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * V2 §7 — combined activity/participation score from four signal types:
 *   registration → registering interest (debater solo, judge, or coach whose
 *                  team registered as a whole) — full credit even if never
 *                  selected, this is a positive-interest signal on its own.
 *   attendance   → actually showing up once selected/rostered (reuses the
 *                  V1 §6.5 sticky stamps: prep_attended_at for debaters,
 *                  first_attended_at for trainer/judge).
 *   viewing      → watched a debate as a non-participant (new §7 signal,
 *                  DebateViewer, recorded by the LiveKit webhook).
 *   penalty      → selected/rostered but did NOT attend (mirror of
 *                  attendance — same rows, penalized instead of rewarded).
 *
 * `value` is a raw weighted point total, NOT a percentage — the work order's
 * illustrative "6%" framing needs a defined ceiling that was never specified;
 * a real percentage can be added once product picks a max to normalize
 * against. Weights are flat, not Elo-adjusted (see config('debate.activity')
 * — this isn't a contest, so there's no "opponent" to weigh against).
 */
class ActivityStatsService
{
    private const ROLES = ['debater', 'trainer', 'judge'];

    public function activity(User $user, StatsFilter $f): array
    {
        $cfg = config('debate.activity');
        $events = collect();

        // ── Registration: every participant row, regardless of status ──────────
        $registrations = DebateParticipant::where('user_id', $user->id)
            ->whereIn('role', self::ROLES)
            ->whereHas('debate', function ($q) use ($f) {
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with('debate:id,scheduled_at')
            ->get();

        foreach ($registrations as $p) {
            $events->push(['date' => $p->debate->scheduled_at, 'type' => 'registration', 'points' => $cfg['registration_points']]);
        }

        // ── Attendance / penalty: only selected (approved) rows on debates that
        // actually happened (completed). For debaters, mirrors the V1 §6.5
        // fairness rule — a debate whose prep rooms never opened can't be missed.
        $selected = DebateParticipant::where('user_id', $user->id)
            ->whereIn('role', self::ROLES)
            ->where('status', 'approved')
            ->whereHas('debate', function ($q) use ($f) {
                $q->where('status', 'completed');
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with('debate:id,scheduled_at,prep_rooms_opened_at')
            ->get()
            ->filter(fn (DebateParticipant $p) => $p->role !== 'debater' || $p->debate->prep_rooms_opened_at !== null);

        foreach ($selected as $p) {
            $attended = $p->role === 'debater' ? $p->prep_attended_at !== null : $p->first_attended_at !== null;
            $type = $attended ? 'attendance' : 'penalty';
            $points = $attended ? $cfg['attendance_points'][$p->role] : $cfg['penalty_points'][$p->role];
            $events->push(['date' => $p->debate->scheduled_at, 'type' => $type, 'points' => $points]);
        }

        // ── Viewing ──────────────────────────────────────────────────────────────
        $views = DebateViewer::where('user_id', $user->id)
            ->whereHas('debate', function ($q) use ($f) {
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with('debate:id,scheduled_at')
            ->get();

        foreach ($views as $v) {
            $events->push(['date' => $v->debate->scheduled_at, 'type' => 'viewing', 'points' => $cfg['viewing_points']]);
        }

        $buckets = [];
        foreach ($this->bucketize($events, $f->groupBy, $f) as $label => $bucketEvents) {
            $buckets[] = [
                'label'     => $label,
                'value'     => round($bucketEvents->sum('points'), 2),
                'breakdown' => $this->breakdown($bucketEvents),
            ];
        }

        return [
            'stat'     => 'activity',
            'grouping' => match ($f->groupBy) {
                'year'  => 'by_year',
                'month' => 'by_month',
                default => 'none',
            },
            'buckets'  => $buckets,
            'totals'   => [
                'value'     => round($events->sum('points'), 2),
                'breakdown' => $this->breakdown($events),
            ],
        ];
    }

    private function breakdown(Collection $events): array
    {
        $out = [];
        foreach (['registration', 'attendance', 'viewing', 'penalty'] as $type) {
            $typeEvents = $events->where('type', $type);
            $out[$type] = [
                'points' => round($typeEvents->sum('points'), 2),
                'count'  => $typeEvents->count(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, Collection> chronologically ordered, gap-free
     *
     * MF_FU §6.3 — quiet periods are emitted as zero-valued buckets rather than
     * omitted. A trend line drawn from sparse buckets silently closes the gap
     * over an inactive month and overstates the slope; the client should see the
     * flat stretch. The filled span runs from `from` (or the first event) to
     * `to` (or the last event), so an explicit range zero-fills its whole width
     * even when there is no activity at either edge.
     */
    private function bucketize(Collection $events, string $groupBy, StatsFilter $f): array
    {
        if ($groupBy === 'none') {
            return $events->isEmpty() ? [] : ['all' => $events];
        }

        $fmt = $groupBy === 'year' ? 'Y' : 'Y-m';
        $grouped = $events->groupBy(fn (array $e) => Carbon::parse($e['date'])->format($fmt))->sortKeys();

        $dates = $events->map(fn (array $e) => Carbon::parse($e['date']));
        $start = $f->fromDate()  ?? ($dates->isEmpty() ? null : $dates->min()->copy());
        $end   = $f->toDateEnd() ?? ($dates->isEmpty() ? null : $dates->max()->copy());

        if ($start === null || $end === null || $start->gt($end)) {
            return $grouped->all();
        }

        $out = [];
        $cursor = $start->copy()->startOfMonth();
        $limit  = (int) config('debate.stats.max_zero_filled_buckets', 240);

        while ($cursor->lte($end) && count($out) < $limit) {
            $label = $cursor->format($fmt);
            $out[$label] = $grouped->get($label, collect());
            $cursor = $groupBy === 'year' ? $cursor->addYear() : $cursor->addMonth();
        }

        // Anything beyond the cap (a pathological range) still gets its real
        // buckets appended rather than silently dropped.
        foreach ($grouped as $label => $bucket) {
            $out[$label] ??= $bucket;
        }

        ksort($out);

        return $out;
    }
}
