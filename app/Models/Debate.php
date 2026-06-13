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
        'prop_room_name',
        'opp_room_name',
        'result_room_name',
        'recording_url',
        'transcript',
        'scheduled_at',
        'tag',
        'started_at',
        'ended_at',
        'current_stage',
        'motion_revealed_at',
        'prep_rooms_opened_at',
        'result_revealed_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'              => 'string',
            'current_stage'       => 'integer',
            'scheduled_at'        => 'datetime',
            'started_at'          => 'datetime',
            'ended_at'            => 'datetime',
            'motion_revealed_at'  => 'datetime',
            'prep_rooms_opened_at' => 'datetime',
            'result_revealed_at'  => 'datetime',
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
            ->withPivot([
                'team_id', 'role', 'side', 'status', 'is_chair',
                'is_attended', 'speaking_phase_order', 'judge_order',
            ])
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
        return $this->hasMany(Feedbacks::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    public function propRoomParticipants(): HasMany
    {
        return $this->hasMany(DebateParticipant::class)->where('side', 'proposition');
    }

    public function oppRoomParticipants(): HasMany
    {
        return $this->hasMany(DebateParticipant::class)->where('side', 'opposition');
    }

    public function judges(): HasMany
    {
        return $this->hasMany(DebateParticipant::class)->where('role', 'judge');
    }

    public function chairJudge(): HasOne
    {
        return $this->hasOne(DebateParticipant::class)
            ->where('role', 'judge')
            ->where('is_chair', true);
    }
}
