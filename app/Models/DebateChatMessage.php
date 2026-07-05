<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebateChatMessage extends Model
{
    protected $fillable = [
        'debate_id',
        'team_id',
        'sender_id',
        'message',
    ];

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(DebateChatMessageRead::class, 'message_id');
    }

    /** Wire shape shared by GET /chat and POST /chat (§2). */
    public function toWire(): array
    {
        return [
            'id'          => (int) $this->id,
            'sender_id'   => (int) $this->sender_id,
            'sender_name' => $this->sender?->name,
            'message'     => $this->message,
            'sent_at'     => $this->created_at?->toIso8601String(),
            'seen_by'     => $this->reads->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }
}
