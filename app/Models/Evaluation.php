<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'trainer_id',
        'debater_id',
        'debate_id',
        'notes',
        'scores',
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function debater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'debater_id');
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }
}
