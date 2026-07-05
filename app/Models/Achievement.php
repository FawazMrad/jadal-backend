<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Achievement extends Model
{
    /** Rank display/sort order: gold outranks silver outranks bronze, etc. */
    public const RANK_ORDER = ['gold', 'silver', 'bronze', 'honoring', 'participation'];

    protected $fillable = [
        'user_id',
        'name',
        'rank',
        'image_url',
        'awarded_at',
    ];

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Portable highest-rank-first ordering (works on both MySQL and the
     * SQLite test database; `rank` is a reserved word in MySQL 8, hence the
     * backticks).
     */
    public function scopeOrderByRankThenRecency($query)
    {
        $cases = [];
        foreach (self::RANK_ORDER as $i => $rank) {
            $cases[] = "WHEN '{$rank}' THEN " . ($i + 1);
        }

        return $query
            ->orderByRaw('CASE `rank` ' . implode(' ', $cases) . ' ELSE 99 END')
            ->orderByDesc('awarded_at');
    }
}
