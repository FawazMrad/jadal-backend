<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DebateParticipant extends Pivot
{
    use HasFactory;

    protected $table = 'debate_participants';

    public $incrementing = true;

    protected $fillable = [
        'debate_id',
        'user_id',
        'team_id',
        'role',
        'side',
        'status',
        'is_chair',
        'is_attended',
        'speaking_phase_order',
        'judge_order',
    ];

    protected function casts(): array
    {
        return [
            'is_chair'            => 'boolean',
            'is_attended'         => 'boolean',
            'speaking_phase_order' => 'integer',
            'judge_order'         => 'integer',
            'role'                => 'string',
            'side'                => 'string',
            'status'              => 'string',
        ];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
