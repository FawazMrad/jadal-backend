<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebateChatMessageRead extends Model
{
    /** Composite (message_id, user_id) primary key — no auto id, no timestamps. */
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = null;

    protected $fillable = [
        'message_id',
        'user_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
