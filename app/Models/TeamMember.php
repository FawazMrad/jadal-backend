<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    protected $fillable = [
        'team_id',
        'user_id',
        'priority',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'status'   => 'string',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
