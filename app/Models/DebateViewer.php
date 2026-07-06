<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebateViewer extends Model
{
    public $timestamps = false;

    protected $fillable = ['debate_id', 'user_id', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
