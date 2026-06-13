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
        'participant_id',
        'name',
        'order_index',
        'duration_seconds',
        'status',
        'started_at',
        'ended_at',
        'audio_url',
        'speech_text',
        'poi_raised_count',
        'poi_answered_count',
        'is_reply',
        'egress_id',
    ];

    protected function casts(): array
    {
        return [
            'order_index'        => 'integer',
            'duration_seconds'   => 'integer',
            'status'             => 'string',
            'started_at'         => 'datetime',
            'ended_at'           => 'datetime',
            'poi_raised_count'   => 'integer',
            'poi_answered_count' => 'integer',
            'is_reply'           => 'boolean',
        ];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(DebateParticipant::class, 'participant_id');
    }
}
