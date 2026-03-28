<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motion extends Model
{
    use HasFactory;

    protected $fillable = [
        'added_by',
        'text',
    ];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function frameworks(): BelongsToMany
    {
        return $this->belongsToMany(
            MotionFramework::class,
            'motion_framework_pivot',
            'motion_id',
            'framework_id'
        );
    }

    public function debates(): HasMany
    {
        return $this->hasMany(Debate::class, 'motion_id');
    }
}
