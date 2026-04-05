<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Debate extends Model
{
    use HasFactory;

    protected $fillable = [
        'format_id',
        'motion_id',
        'created_by',
        'title',
        'description',
        'status',
        'livekit_room_name',
        'recording_url',
        'transcript',
        'scheduled_at',
        'tag',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => 'string',
            'scheduled_at' => 'datetime',
            'started_at'   => 'datetime',
            'ended_at'     => 'datetime',
        ];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(DebateFormat::class, 'format_id');
    }

    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class, 'motion_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(DebateParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'debate_participants')
            ->using(DebateParticipant::class)
            ->withPivot(['team_id', 'role', 'side', 'status', 'is_chair', 'is_attended', 'speaking_phase_order'])
            ->withTimestamps();
    }

    public function phases(): HasMany
    {
        return $this->hasMany(DebatePhase::class)->orderBy('order_index');
    }

    public function result(): HasOne
    {
        return $this->hasOne(DebateResult::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
