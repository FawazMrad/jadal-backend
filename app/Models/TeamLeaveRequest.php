<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamLeaveRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['team_id', 'user_id', 'status', 'reason', 'responded_at'];

    protected $casts = [
        'responded_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
