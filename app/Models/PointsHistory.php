<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PointsHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'debate_id',
        'points_before',
        'delta',
        'points_after',
        'breakdown',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'breakdown'  => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }
}
