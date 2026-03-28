<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MotionFramework extends Model
{
    use HasFactory;

    public $timestamps = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'color_hex',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function motions(): BelongsToMany
    {
        return $this->belongsToMany(
            Motion::class,
            'motion_framework_pivot',
            'framework_id',
            'motion_id'
        );
    }
}
