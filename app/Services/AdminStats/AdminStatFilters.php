<?php

namespace App\Services\AdminStats;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Normalized universal filters for the ADMIN platform-stats module
 * (from / to / group_by / formats), shared by all five stat services.
 *
 * Kept separate from App\Services\Stats\StatsFilter on purpose: that class
 * belongs to the per-debater mobile module (frameworks/positions/teams/series
 * semantics) which this module must not touch; the admin module only shares
 * its from/to conventions (YYYY-MM, inclusive month bounds).
 */
class AdminStatFilters
{
    public function __construct(
        public readonly ?string $from = null,      // YYYY-MM
        public readonly ?string $to = null,        // YYYY-MM
        public readonly string $groupBy = 'none',  // none|year|month
        public readonly array $formats = [],       // int[] debate_formats.id
    ) {}

    public static function fromArray(array $v): self
    {
        $formats = [];
        if (! empty($v['formats'])) {
            $formats = array_values(array_unique(array_map(
                'intval',
                array_filter(array_map('trim', explode(',', (string) $v['formats'])), fn ($s) => $s !== '')
            )));
        }

        return new self(
            from:    $v['from'] ?? null,
            to:      $v['to'] ?? null,
            groupBy: $v['group_by'] ?? 'none',
            formats: $formats,
        );
    }

    public function fromDate(): ?CarbonInterface
    {
        return $this->from ? Carbon::createFromFormat('Y-m', $this->from)->startOfMonth() : null;
    }

    public function toDateEnd(): ?CarbonInterface
    {
        return $this->to ? Carbon::createFromFormat('Y-m', $this->to)->endOfMonth() : null;
    }

    /** Apply the from/to window to a date column (inclusive month bounds). */
    public function applyWindow(Builder|EloquentBuilder $q, string $column): Builder|EloquentBuilder
    {
        if ($from = $this->fromDate()) {
            $q->where($column, '>=', $from);
        }
        if ($to = $this->toDateEnd()) {
            $q->where($column, '<=', $to);
        }

        return $q;
    }

    /** Apply the formats filter to a debates.format_id column reference. */
    public function applyFormats(Builder|EloquentBuilder $q, string $column = 'debates.format_id'): Builder|EloquentBuilder
    {
        if (! empty($this->formats)) {
            $q->whereIn($column, $this->formats);
        }

        return $q;
    }

    /**
     * SQL expression producing the bucket label for a date column under the
     * active group_by ('2026' / '2026-07' / the constant 'all'). Branches on
     * driver because prod is MariaDB and the test suite runs on SQLite.
     */
    public function bucketExpression(string $column): string
    {
        if ($this->groupBy === 'none') {
            return "'all'";
        }

        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $fmt = $this->groupBy === 'year' ? '%Y' : '%Y-%m';

        return $sqlite
            ? "strftime('{$fmt}', {$column})"
            : "DATE_FORMAT({$column}, '{$fmt}')";
    }

    /**
     * The month-span guard shared with the mobile module: group_by=month with
     * no explicit from+to is rejected when the data would span more than the
     * configured number of months (prevents unbounded payloads). $earliest is
     * the oldest relevant date in the data (null = no data → no violation).
     */
    public function violatesMonthSpanGuard(?CarbonInterface $earliest): bool
    {
        if ($this->groupBy !== 'month' || ($this->from && $this->to)) {
            return false;
        }
        if ($earliest === null) {
            return false;
        }

        $guard = (int) config('debate.stats.month_span_guard', 24);
        $end = $this->toDateEnd() ?? Carbon::now();
        $start = $this->fromDate() ?? $earliest;

        return (int) $start->diffInMonths($end) > $guard;
    }

    /** Stable identity of this filter set, for cache keys. */
    public function cacheKeyPart(): string
    {
        return md5(json_encode([
            'from'     => $this->from,
            'to'       => $this->to,
            'group_by' => $this->groupBy,
            'formats'  => $this->formats,
        ]));
    }
}
