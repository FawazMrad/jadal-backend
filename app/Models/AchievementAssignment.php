<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Junction row: user_id has been awarded achievement_id, by assigned_by
 * (nullable — null on rows backfilled from the pre-catalog schema, which
 * never recorded an awarding admin), at assigned_at. Unique per
 * (user_id, achievement_id) — enforced at the DB level.
 */
class AchievementAssignment extends Pivot
{
    protected $table = 'achievement_assignments';

    public $incrementing = true;

    /** No created_at/updated_at columns — assigned_at is the only timestamp this table needs. */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'achievement_id',
        'assigned_at',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
