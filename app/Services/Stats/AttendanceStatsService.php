<?php

namespace App\Services\Stats;

use App\Models\DebateParticipant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Sprinkles §6.5 — the "attendance" stat, three flavors sharing one engine:
 *
 *   debater → did they join their PREP room for debates they were rostered on
 *             (prep_attended_at, only debates whose prep rooms actually opened)
 *   trainer → did they join the MAIN room while their team debated (first_attended_at)
 *   judge   → did they join the MAIN room for debates they judged (first_attended_at)
 *
 * Fairness rule (per the work order): the rate is attended ÷ SELECTED.
 * "Registered but never selected" (participant row that stayed pending or was
 * rejected) is excluded from the denominator entirely — it is additionally
 * surfaced via totals.registered so the FE can show "puts themselves forward"
 * as a positive signal.
 */
class AttendanceStatsService
{
    public function attendance(User $user, string $role, StatsFilter $f): array
    {
        // Every participant row for this user+role on a debate that actually
        // happened (status=completed). For prep attendance the debate must
        // also have opened its prep rooms — a debate whose prep never opened
        // can't be missed.
        $rows = DebateParticipant::query()
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->whereHas('debate', function ($q) use ($f, $role) {
                $q->where('status', 'completed');

                if ($role === 'debater') {
                    $q->whereNotNull('prep_rooms_opened_at');
                }
                if ($from = $f->fromDate()) {
                    $q->where('scheduled_at', '>=', $from);
                }
                if ($to = $f->toDateEnd()) {
                    $q->where('scheduled_at', '<=', $to);
                }
            })
            ->with('debate:id,scheduled_at,status,prep_rooms_opened_at')
            ->get();

        $selected = $rows->where('status', 'approved')->values();

        $attendedField = $role === 'debater' ? 'prep_attended_at' : 'first_attended_at';
        $isAttended = fn (DebateParticipant $p) => $p->{$attendedField} !== null;

        $buckets = [];
        foreach ($this->bucketize($selected, $f->groupBy) as $label => $bucketRows) {
            $n = $bucketRows->count();
            $attended = $bucketRows->filter($isAttended)->count();

            $buckets[] = [
                'label'  => $label,
                'series' => [[
                    'key'      => 'all',
                    'label'    => 'All',
                    'value'    => $n > 0 ? round($attended / $n, 4) : null,
                    'attended' => $attended,
                    'selected' => $n,
                ]],
            ];
        }

        $totalSelected = $selected->count();
        $totalAttended = $selected->filter($isAttended)->count();

        return [
            'stat'     => $role === 'debater' ? 'prep_attendance' : 'attendance',
            'role'     => $role,
            'grouping' => match ($f->groupBy) {
                'year'  => 'by_year',
                'month' => 'by_month',
                default => 'none',
            },
            'buckets'  => $buckets,
            'totals'   => [
                // How often they put themselves forward (includes pending/rejected).
                'registered' => $rows->count(),
                'selected'   => $totalSelected,
                'attended'   => $totalAttended,
                'rate'       => $totalSelected > 0 ? round($totalAttended / $totalSelected, 4) : null,
            ],
        ];
    }

    /** @return array<string, Collection<int, DebateParticipant>> chronologically ordered */
    private function bucketize(Collection $rows, string $groupBy): array
    {
        if ($groupBy === 'none') {
            return $rows->isEmpty() ? [] : ['all' => $rows];
        }

        $fmt = $groupBy === 'year' ? 'Y' : 'Y-m';

        return $rows
            ->groupBy(fn (DebateParticipant $p) => Carbon::parse($p->debate->scheduled_at)->format($fmt))
            ->sortKeys()
            ->all();
    }
}
