<?php

namespace App\Services\Stats;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Normalized, validated filter inputs shared by every stat endpoint.
 */
class StatsFilter
{
    public function __construct(
        public readonly ?string $from = null,        // YYYY-MM
        public readonly ?string $to = null,          // YYYY-MM
        public readonly string $groupBy = 'none',    // none|year|month
        public readonly array $frameworks = [],      // int[]
        public readonly array $positions = [],       // code strings
        public readonly array $teams = [],           // string[] (team ids and/or 'RANDOM')
        public readonly string $series = 'none',     // frameworks|positions|teams|none
        public readonly ?string $rankingMode = null, // top|bottom|latest|earliest (stat 3)
        public readonly int $limit = 5,              // stat 3
    ) {}

    public static function fromArray(array $v): self
    {
        return new self(
            from:        $v['from'] ?? null,
            to:          $v['to'] ?? null,
            groupBy:     $v['group_by'] ?? 'none',
            frameworks:  self::splitInts($v['frameworks'] ?? null),
            positions:   self::splitStrings($v['positions'] ?? null),
            teams:       self::splitStrings($v['teams'] ?? null),
            series:      $v['series'] ?? 'none',
            rankingMode: $v['ranking_mode'] ?? null,
            limit:       isset($v['limit']) ? (int) $v['limit'] : 5,
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

    /** Slot set for the active position filter, or null when no position filter. */
    public function positionSlots(): ?array
    {
        return empty($this->positions) ? null : PositionCodes::expandMany($this->positions);
    }

    /**
     * The series dimension actually in effect. Per the spec, if `series` is set
     * but its underlying filter has < 2 selected values, silently fall back to
     * 'none'. `series` is meaningless for the ranking/improvement stats.
     */
    public function effectiveSeries(): string
    {
        return match ($this->series) {
            'frameworks' => count($this->frameworks) >= 2 ? 'frameworks' : 'none',
            'positions'  => count($this->positions) >= 2 ? 'positions' : 'none',
            'teams'      => count($this->teams) >= 2 ? 'teams' : 'none',
            default      => 'none',
        };
    }

    private static function splitInts(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_unique(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $csv)), fn ($s) => $s !== '')
        )));
    }

    private static function splitStrings(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_unique(
            array_filter(array_map('trim', explode(',', $csv)), fn ($s) => $s !== '')
        ));
    }
}
