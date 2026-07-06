<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'avatar_url',
        'phone',
        'points',
        'birth_date',
        'location',
        'stats_visible',
        'livekit_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'points'            => 'integer',
            'birth_date'        => 'date',
            'stats_visible'     => 'boolean',
            'role'              => 'string',
            'status'            => 'string',
        ];
    }

    /** Age in whole years, computed from birth_date — never stored, never stale. */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    // ── Achievements ──────────────────────────────────────────────────────────

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    public function pointsHistories(): MorphMany
    {
        return $this->morphMany(PointsHistory::class, 'subject');
    }

    // ── Motions ──────────────────────────────────────────────────────────────

    public function motions(): HasMany
    {
        return $this->hasMany(Motion::class, 'added_by');
    }

    // ── Teams ─────────────────────────────────────────────────────────────────

    public function ledTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'leader_id');
    }

    public function createdTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'created_by');
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot(['priority', 'status'])
            ->withTimestamps();
    }

    // ── Debates ───────────────────────────────────────────────────────────────

    public function createdDebates(): HasMany
    {
        return $this->hasMany(Debate::class, 'created_by');
    }

    public function debateParticipations(): HasMany
    {
        return $this->hasMany(DebateParticipant::class);
    }

    public function debates(): BelongsToMany
    {
        return $this->belongsToMany(Debate::class, 'debate_participants')
            ->using(DebateParticipant::class)
            ->withPivot(['team_id', 'role', 'side', 'status', 'is_chair', 'is_attended', 'speaking_phase_order'])
            ->withTimestamps();
    }

    // ── Results / Feedback / Evaluations ─────────────────────────────────────

    public function judgedResults(): HasMany
    {
        return $this->hasMany(DebateResult::class, 'judge_id');
    }

    public function givenFeedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'from_user_id');
    }

    public function receivedFeedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'to_user_id');
    }

    public function trainerEvaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'trainer_id');
    }

    public function debaterEvaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'debater_id');
    }

    // ── Other ─────────────────────────────────────────────────────────────────

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function filedComplaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'filed_by');
    }

    public function blogPosts(): HasMany
    {
        return $this->hasMany(BlogPost::class, 'author_id');
    }

    public function createdSurveys(): HasMany
    {
        return $this->hasMany(Survey::class, 'created_by');
    }

    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
