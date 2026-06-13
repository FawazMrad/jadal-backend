<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebateResult extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'debate_id',
        'judge_id',
        'contributing_judges',
        'winning_side',
        'scores',
        'summary_notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'scores'              => 'array',
            'contributing_judges' => 'array',
            'winning_side'        => 'string',
            'submitted_at'        => 'datetime',
        ];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id');
    }
}
