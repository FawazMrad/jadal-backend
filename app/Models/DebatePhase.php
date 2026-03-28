<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebatePhase extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'debate_id',
        'name',
        'order_index',
        'duration_seconds',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'order_index'      => 'integer',
            'duration_seconds' => 'integer',
            'status'           => 'string',
            'started_at'       => 'datetime',
            'ended_at'         => 'datetime',
        ];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }
}
