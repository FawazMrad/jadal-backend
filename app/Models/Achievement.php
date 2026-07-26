<?php

namespace App\Models;

use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Achievement CATALOG entry (name + type + image). Not tied to any one user —
 * see AchievementAssignment for "which users have earned this achievement."
 */
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory;

    /**
     * The 5 static types, in display/sort priority (gold outranks silver
     * outranks bronze outranks honorable outranks participation). This is a
     * deliberate product decision, not literally the order the types are
     * listed in the spec — "honorable mention" is treated as more notable
     * than a plain participation credit, matching how the old `honoring`
     * rank was ordered before this type was renamed.
     */
    public const TYPES = ['GOLD', 'SILVER', 'BRONZE', 'HONORABLE', 'PARTICIPATION'];

    protected $fillable = [
        'name',
        'type',
        'image_url',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(AchievementAssignment::class);
    }

    /**
     * Portable highest-rank-first, most-recently-assigned-first ordering.
     * Only valid on a query that already joins achievement_assignments
     * (i.e. called via $user->achievements()->...), since it orders by that
     * table's assigned_at.
     */
    public function scopeOrderByRankThenRecency($query)
    {
        $cases = [];
        foreach (self::TYPES as $i => $type) {
            $cases[] = "WHEN '{$type}' THEN " . ($i + 1);
        }

        return $query
            ->orderByRaw('CASE `achievements`.`type` ' . implode(' ', $cases) . ' ELSE 99 END')
            ->orderByDesc('achievement_assignments.assigned_at');
    }
}
