<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebateFormat extends Model
{
    use HasFactory;

    public const SPEAKERS_PER_SIDE = 3;

    /**
     * Protected period, in seconds — the window at the START and END of a
     * speech during which no POI may be offered. 60s matches the value the
     * Flutter client has been hard-coding, so adopting this default changes
     * nothing about how existing debates run; it just moves the number from
     * the app into configuration where an organiser can edit it.
     */
    public const DEFAULT_PROTECTED_TIME_SECONDS = 60;

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

    public function deriveStages(): array
    {
        $stages = [];
        $order  = 0;

        for ($speakerNum = 1; $speakerNum <= self::SPEAKERS_PER_SIDE; $speakerNum++) {
            $stages[] = [
                'order_index'      => ++$order,
                'name'             => "Proposition {$speakerNum}",
                'role'             => 'proposition',
                'is_reply'         => false,
                'duration_seconds' => $this->phase_config['speech_time_seconds'],
            ];
            $stages[] = [
                'order_index'      => ++$order,
                'name'             => "Opposition {$speakerNum}",
                'role'             => 'opposition',
                'is_reply'         => false,
                'duration_seconds' => $this->phase_config['speech_time_seconds'],
            ];
        }

        if ($this->phase_config['has_reply_speech'] ?? false) {
            // Reply order is intentionally INVERTED vs the main speeches:
            // Opposition reply comes FIRST, Proposition reply comes SECOND.
            $stages[] = [
                'order_index'      => ++$order,
                'name'             => 'Opposition Reply',
                'role'             => 'opposition',
                'is_reply'         => true,
                'duration_seconds' => $this->phase_config['reply_time_seconds'],
            ];
            $stages[] = [
                'order_index'      => ++$order,
                'name'             => 'Proposition Reply',
                'role'             => 'proposition',
                'is_reply'         => true,
                'duration_seconds' => $this->phase_config['reply_time_seconds'],
            ];
        }

        return $stages;
    }
}
