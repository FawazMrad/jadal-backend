<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'filed_by',
        'debate_id',
        'target_user_id',
        'target_role',
        'description',
        'status',
        'admin_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
        ];
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /** Who the complaint is about (null on legacy/unattributed rows). */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function debate(): BelongsTo
    {
        return $this->belongsTo(Debate::class);
    }
}
