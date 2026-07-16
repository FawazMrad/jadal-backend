<?php

namespace App\Services\AdminStats;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stat 3 — Platform Growth & Health Overview (the admin homepage pulse-check).
 *
 * Every number is one grouped aggregate query — never a PHP loop over models:
 *   new_users          — users.created_at bucket × role
 *   debates_created    — debates.created_at bucket
 *   debates_completed  — completed+revealed, bucketed by scheduled_at
 *   debates_cancelled  — status=cancelled, bucketed by scheduled_at,
 *                        broken down by cancellation_reason
 *   participation      — debater rows on completed debates (participations +
 *                        distinct active debaters) per scheduled_at bucket
 *
 * Note on completion_rate: created is dated by created_at while completed is
 * dated by scheduled_at (per spec), so a single bucket's ratio can exceed 1
 * when debates created earlier complete inside it — expected, documented.
 */
class PlatformHealthService
{
    private const ROLES = ['debater', 'trainer', 'judge', 'admin'];

    public function __construct(private StatsCache $cache) {}

    public function compute(AdminStatFilters $f, string $series): array
    {
        $key = $f->cacheKeyPart() . ":s{$series}";

        return $this->cache->remember('platform_health', $key, fn () => $this->build($f, $series));
    }

    /** Earliest relevant date across the tables this stat buckets (month-span guard). */
    public function earliestDataPoint(): ?CarbonInterface
    {
        $candidates = array_filter([
            DB::table('users')->min('created_at'),
            DB::table('debates')->min('created_at'),
            DB::table('debates')->min('scheduled_at'),
        ]);

        return empty($candidates) ? null : Carbon::parse(min($candidates));
    }

    private function build(AdminStatFilters $f, string $series): array
    {
        $buckets = [];
        $touch = function (string $label) use (&$buckets): array {
            $buckets[$label] ??= [
                'label'                  => $label,
                'new_users'              => array_fill_keys(self::ROLES, 0),
                'debates_created'        => 0,
                'debates_completed'      => 0,
                'debates_cancelled'      => 0,
                'cancellation_breakdown' => [],
                'completion_rate'        => null,
                'avg_debates_per_active_debater' => null,
                '_participations'        => 0,
                '_active_debaters'       => 0,
            ];

            return $buckets;
        };

        // ── new users (role-dimensional by nature) ──────────────────────────
        $uBucket = $f->bucketExpression('users.created_at');
        $newUsers = DB::table('users')
            ->tap(fn ($q) => $f->applyWindow($q, 'users.created_at'))
            ->groupByRaw("{$uBucket}, role")
            ->selectRaw("{$uBucket} as bucket, role, COUNT(*) as c")
            ->get();
        foreach ($newUsers as $r) {
            $touch($r->bucket);
            $buckets[$r->bucket]['new_users'][$r->role] = (int) $r->c;
        }

        // ── debates created ─────────────────────────────────────────────────
        $cBucket = $f->bucketExpression('debates.created_at');
        $created = DB::table('debates')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.created_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw($cBucket)
            ->selectRaw("{$cBucket} as bucket, COUNT(*) as c")
            ->get();
        foreach ($created as $r) {
            $touch($r->bucket);
            $buckets[$r->bucket]['debates_created'] = (int) $r->c;
        }

        // ── debates completed (dated by scheduled_at) ───────────────────────
        $sBucket = $f->bucketExpression('debates.scheduled_at');
        $completed = DB::table('debates')
            ->where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw($sBucket)
            ->selectRaw("{$sBucket} as bucket, COUNT(*) as c")
            ->get();
        foreach ($completed as $r) {
            $touch($r->bucket);
            $buckets[$r->bucket]['debates_completed'] = (int) $r->c;
        }

        // ── debates cancelled + reason breakdown ────────────────────────────
        $cancelled = DB::table('debates')
            ->where('status', 'cancelled')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw("{$sBucket}, cancellation_reason")
            ->selectRaw("{$sBucket} as bucket, cancellation_reason, COUNT(*) as c")
            ->get();
        foreach ($cancelled as $r) {
            $touch($r->bucket);
            $reason = $r->cancellation_reason ?? 'unspecified';
            $buckets[$r->bucket]['debates_cancelled'] += (int) $r->c;
            $buckets[$r->bucket]['cancellation_breakdown'][$reason] =
                ($buckets[$r->bucket]['cancellation_breakdown'][$reason] ?? 0) + (int) $r->c;
        }

        // ── debater participation on completed debates ──────────────────────
        $dBucket = $f->bucketExpression('d.scheduled_at');
        $participation = DB::table('debate_participants as dp')
            ->join('debates as d', 'd.id', '=', 'dp.debate_id')
            ->where('dp.role', 'debater')
            ->where('d.status', 'completed')
            ->whereNotNull('d.result_revealed_at')
            ->tap(fn ($q) => $f->applyWindow($q, 'd.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'd.format_id'))
            ->groupByRaw($dBucket)
            ->selectRaw("{$dBucket} as bucket, COUNT(*) as participations, COUNT(DISTINCT dp.user_id) as actives")
            ->get();
        foreach ($participation as $r) {
            $touch($r->bucket);
            $buckets[$r->bucket]['_participations'] = (int) $r->participations;
            $buckets[$r->bucket]['_active_debaters'] = (int) $r->actives;
        }

        // ── optional series split ────────────────────────────────────────────
        $seriesByBucket = $series === 'debate_format' ? $this->formatSeries($f, $sBucket, $cBucket) : [];

        // ── derive ratios, attach series, drop helper keys ──────────────────
        ksort($buckets);
        $out = [];
        foreach ($buckets as $label => $b) {
            $b['completion_rate'] = $b['debates_created'] > 0
                ? round($b['debates_completed'] / $b['debates_created'], 4)
                : null;
            $b['avg_debates_per_active_debater'] = $b['_active_debaters'] > 0
                ? round($b['_participations'] / $b['_active_debaters'], 2)
                : null;
            unset($b['_participations'], $b['_active_debaters']);

            if ($series === 'debate_format') {
                $b['by_format'] = array_values($seriesByBucket[$label] ?? []);
            } elseif ($series === 'role') {
                // Role is only a dimension of user signups — expose the same
                // breakdown under the series key so charting code has one
                // consistent place to look when a series is requested.
                $b['by_role'] = $b['new_users'];
            }

            $out[] = $b;
        }

        return [
            'stat'    => 'platform_health',
            'series'  => $series,
            'buckets' => $out,
        ];
    }

    /**
     * series=debate_format — created/completed/cancelled per format per bucket.
     * @return array<string, array<int, array>>  bucket label → format rows
     */
    private function formatSeries(AdminStatFilters $f, string $sBucket, string $cBucket): array
    {
        $names = DB::table('debate_formats')->pluck('name', 'id');
        $out = [];

        $ensure = function (string $bucket, int $formatId) use (&$out, $names): void {
            $out[$bucket][$formatId] ??= [
                'format_id'         => $formatId,
                'format_name'       => (string) ($names[$formatId] ?? $formatId),
                'debates_created'   => 0,
                'debates_completed' => 0,
                'debates_cancelled' => 0,
            ];
        };

        $created = DB::table('debates')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.created_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw("{$cBucket}, format_id")
            ->selectRaw("{$cBucket} as bucket, format_id, COUNT(*) as c")
            ->get();
        foreach ($created as $r) {
            $ensure($r->bucket, (int) $r->format_id);
            $out[$r->bucket][(int) $r->format_id]['debates_created'] = (int) $r->c;
        }

        $completed = DB::table('debates')
            ->where('status', 'completed')
            ->whereNotNull('result_revealed_at')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw("{$sBucket}, format_id")
            ->selectRaw("{$sBucket} as bucket, format_id, COUNT(*) as c")
            ->get();
        foreach ($completed as $r) {
            $ensure($r->bucket, (int) $r->format_id);
            $out[$r->bucket][(int) $r->format_id]['debates_completed'] = (int) $r->c;
        }

        $cancelled = DB::table('debates')
            ->where('status', 'cancelled')
            ->tap(fn ($q) => $f->applyWindow($q, 'debates.scheduled_at'))
            ->tap(fn ($q) => $f->applyFormats($q, 'debates.format_id'))
            ->groupByRaw("{$sBucket}, format_id")
            ->selectRaw("{$sBucket} as bucket, format_id, COUNT(*) as c")
            ->get();
        foreach ($cancelled as $r) {
            $ensure($r->bucket, (int) $r->format_id);
            $out[$r->bucket][(int) $r->format_id]['debates_cancelled'] = (int) $r->c;
        }

        return $out;
    }
}
