<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebateFormat extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'phase_config',
    ];

    protected function casts(): array
    {
        return [
            'phase_config' => 'array',
        ];
    }

    public function debates(): HasMany
    {
        return $this->hasMany(Debate::class, 'format_id');
    }
}
