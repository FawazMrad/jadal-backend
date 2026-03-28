<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use HasFactory;

    protected $fillable = [
        'debate_id',
        'from_user_id',
        'to_user_id',
        'type',
        'content',
        'scores',
    ];

    protected function casts(): array
    {
        return [
            'scores' => 'array',
            'type'   => 'string',
        ];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
