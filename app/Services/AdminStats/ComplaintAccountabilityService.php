<?php

namespace App\Services\AdminStats;

use Illuminate\Support\Facades\DB;

/**
 * Stat 5 — Complaint & Accountability Dashboard.
 *
 * Normalizes complaint volume by exposure:
 *   complaints_per_100_debates = complaints_against / debates_involved × 100
 * where debates_involved counts COMPLETED debates in which the user actually
 * held the complained-about capacity ('chair' maps to judge rows with
 * is_chair = true — chair is not a debate_participants.role value).
 *
 * Legacy complaints (target_user_id NULL — every row from before the target
 * columns existed) are excluded from per-person figures but surfaced in
 * unattributed_total so the dashboard's totals stay honest.
 *
 * Resolution time is NOT computable (no resolved_at column exists);
 * avg_time_to_last_update_hours_approx uses updated_at − created_at on rows
 * currently resolved/dismissed and is explicitly labeled an approximation.
 */
class ComplaintAccountabilityService
{
    public const STATUSES = ['open', 'under_review', 'resolved', 'dismissed'];

    public function __construct(private StatsCache $cache) {}

    public function compute(AdminStatFilters $f, ?string $targetRole, ?string $status, int $minDebatesInvolved): array
    {
        $key = $f->cacheKeyPart() . ':' . ($targetRole ?? '-') . ':' . ($status ?? '-') . ":min{$minDebatesInvolved}";

        return $this->cache->remember('complaint_accountability', $key,
            fn () => $this->build($f, $targetRole, $status, $minDebatesInvolved));
    }

    private function build(AdminStatFilters $f, ?string $targetRole, ?string $status, int $minDebatesInvolved): array
    {
        // ── attributed complaint counts per (user, role, status) ────────────
        $counts = DB::table('complaints as c')
            ->whereNotNull('c.target_user_id')
            ->when($targetRole, fn ($q) => $q->where('c.target_role', $targetRole))
            ->when($status, fn ($q) => $q->where('c.status', $status))
            ->tap(fn ($q) => $f->applyWindow($q, 'c.created_at'))
            ->groupBy('c.target_user_id', 'c.target_role', 'c.status')
            ->selectRaw('c.target_user_id as user_id, c.target_role, c.status, COUNT(*) as n')
            ->get();

        // ── unattributed (legacy) total, same window/status lens ────────────
        $unattributed = DB::table('complaints as c')
            ->whereNull('c.target_user_id')
            ->when($status, fn ($q) => $q->where('c.status', $status))
            ->tap(fn ($q) => $f->applyWindow($q, 'c.created_at'))
            ->count();

        if ($counts->isEmpty()) {
            return [
                'stat'               => 'complaint_accountability',
                'unattributed_total' => $unattributed,
                'entries'            => [],
            ];
        }

        $userIds = $counts->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->values();

        // ── approx time-to-last-update on closed rows, per (user, role) ─────
        $closedStatuses = $status
            ? array_values(array_intersect([$status], ['resolved', 'dismissed']))
            : ['resolved', 'dismissed'];

        $avgHours = collect();
        if (! empty($closedStatuses)) {
            $diffExpr = DB::connection()->getDriverName() === 'sqlite'
                ? "(julianday(c.updated_at) - julianday(c.created_at)) * 24.0"
                : 'TIMESTAMPDIFF(SECOND, c.created_at, c.updated_at) / 3600.0';

            $avgHours = DB::table('complaints as c')
                ->whereNotNull('c.target_user_id')
                ->whereIn('c.target_user_id', $userIds)
                ->whereIn('c.status', $closedStatuses)
                ->when($targetRole, fn ($q) => $q->where('c.target_role', $targetRole))
                ->tap(fn ($q) => $f->applyWindow($q, 'c.created_at'))
                ->groupBy('c.target_user_id', 'c.target_role')
                ->selectRaw("c.target_user_id as user_id, c.target_role, AVG({$diffExpr}) as h")
                ->get()
                ->keyBy(fn ($r) => $r->user_id . '|' . $r->target_role);
        }

        // ── exposure: completed debates where the user held each capacity ───
        $involvementRows = DB::table('debate_participants as dp')
            ->join('debates as d', 'd.id', '=', 'dp.debate_id')
            ->whereIn('dp.user_id', $userIds)
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at')
            ->tap(fn ($q) => $f->applyWindow($q, 'd.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'd.format_id'))
            ->groupBy('dp.user_id', 'dp.role', 'dp.is_chair')
            ->selectRaw('dp.user_id, dp.role, dp.is_chair, COUNT(DISTINCT dp.debate_id) as n')
            ->get();

        $involvement = []; // user_id → target_role → completed debates
        foreach ($involvementRows as $r) {
            $uid = (int) $r->user_id;
            $n = (int) $r->n;
            $involvement[$uid][$r->role] = ($involvement[$uid][$r->role] ?? 0) + $n;
            if ($r->role === 'judge' && (bool) $r->is_chair) {
                $involvement[$uid]['chair'] = ($involvement[$uid]['chair'] ?? 0) + $n;
            }
        }

        $names = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        // ── assemble one entry per (user, role) ──────────────────────────────
        $grouped = $counts->groupBy(fn ($r) => $r->user_id . '|' . $r->target_role);

        $entries = [];
        foreach ($grouped as $groupKey => $rows) {
            $userId = (int) $rows->first()->user_id;
            $role = (string) $rows->first()->target_role;

            $breakdown = array_fill_keys(self::STATUSES, 0);
            $total = 0;
            foreach ($rows as $r) {
                $breakdown[$r->status] = (int) $r->n;
                $total += (int) $r->n;
            }

            $involved = (int) ($involvement[$userId][$role] ?? 0);
            if ($involved < $minDebatesInvolved) {
                continue; // too little exposure for the rate to mean anything
            }

            $avg = $avgHours->get($groupKey);

            $entries[] = [
                'user_id'                    => $userId,
                'name'                       => (string) ($names[$userId] ?? $userId),
                'target_role'                => $role,
                'complaints_total'           => $total,
                'debates_involved'           => $involved,
                'complaints_per_100_debates' => $involved > 0 ? round($total / $involved * 100, 2) : null,
                'status_breakdown'           => $breakdown,
                'avg_time_to_last_update_hours_approx' => $avg !== null ? round((float) $avg->h, 1) : null,
            ];
        }

        usort($entries, fn ($a, $b) => [
            $b['complaints_per_100_debates'] ?? -1, $b['complaints_total'], $a['name'],
        ] <=> [
            $a['complaints_per_100_debates'] ?? -1, $a['complaints_total'], $b['name'],
        ]);

        return [
            'stat'               => 'complaint_accountability',
            'unattributed_total' => $unattributed,
            'entries'            => $entries,
        ];
    }
}
