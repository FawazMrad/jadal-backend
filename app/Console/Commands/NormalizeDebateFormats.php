<?php

namespace App\Console\Commands;

use App\Models\DebateFormat;
use Illuminate\Console\Command;

/**
 * Repairs `debate_formats.phase_config` rows that hold the legacy
 * array-of-phases shape instead of the timing object the backend consumes.
 *
 * WHY THIS EXISTS
 * ---------------
 * `DebateFormat::deriveStages()` reads `phase_config['speech_time_seconds']`
 * with no fallback. On a legacy row that key does not exist, so
 * `duration_seconds` resolves to null and the NOT NULL insert into
 * `debate_phases` throws — inside AdvanceDebatesLifecycle, the cron that takes a
 * debate live. In other words: **every debate using a legacy format silently
 * fails to start.**
 *
 * WHY A COMMAND AND NOT A MIGRATION
 * ---------------------------------
 * Two of the five timing keys — `motion_reveal_offset_hours` and
 * `prep_rooms_open_offset_hours` — carry NO equivalent in the legacy shape and
 * cannot be derived. They have to be invented, and inventing them changes when
 * motions are revealed and prep rooms open for real debates. That is not a
 * decision to make silently inside a deploy step, so this runs dry by default,
 * prints exactly what it would write, and only touches data on --force.
 *
 * Idempotent: a row already in the object shape is skipped.
 */
class NormalizeDebateFormats extends Command
{
    protected $signature = 'debate-formats:normalize
        {--force : Actually write the repaired values (default is a dry run)}
        {--motion-reveal-offset=24 : Hours before scheduled_at to reveal the motion, for rows where it cannot be derived}
        {--prep-offset=1 : Hours before scheduled_at to open prep rooms, for rows where it cannot be derived}';

    protected $description = 'Report and repair debate_formats.phase_config rows stored in the legacy array-of-phases shape';

    /** Bounds enforced by StoreDebateFormatRequest — repaired values must satisfy them. */
    private const SPEECH_MIN = 60;
    private const SPEECH_MAX = 1800;
    private const REPLY_MIN  = 60;
    private const REPLY_MAX  = 600;

    public function handle(): int
    {
        $apply         = (bool) $this->option('force');
        $revealOffset  = (float) $this->option('motion-reveal-offset');
        $prepOffset    = (float) $this->option('prep-offset');

        $formats = DebateFormat::orderBy('id')->get();

        if ($formats->isEmpty()) {
            $this->info('No debate formats found.');

            return self::SUCCESS;
        }

        $rows      = [];
        $repairs   = [];
        $okCount   = 0;

        foreach ($formats as $format) {
            $config = $format->phase_config;

            if ($this->isHealthy($config)) {
                $okCount++;
                $rows[] = [$format->id, $format->name, 'OK', '—'];

                continue;
            }

            $repaired = $this->repair($config, $revealOffset, $prepOffset);

            if ($repaired === null) {
                $rows[] = [$format->id, $format->name, 'UNREPAIRABLE', 'no usable durations found — fix by hand'];

                continue;
            }

            $shape = is_array($config) && array_is_list($config) ? 'LEGACY LIST' : 'INCOMPLETE OBJECT';

            $rows[] = [
                $format->id,
                $format->name,
                $shape,
                json_encode($repaired['config'], JSON_UNESCAPED_SLASHES),
            ];

            $repairs[] = ['format' => $format, 'config' => $repaired['config'], 'invented' => $repaired['invented']];
        }

        $this->table(['ID', 'Name', 'Status', 'Proposed phase_config'], $rows);

        $this->newLine();
        $this->line("Healthy: {$okCount}   Needs repair: " . count($repairs) . '   Total: ' . $formats->count());

        if (empty($repairs)) {
            $this->info('Nothing to do — every format is already in the timing-object shape.');

            return self::SUCCESS;
        }

        // The offsets are guesses. Say so every time, in both modes.
        $invented = array_filter($repairs, fn (array $r) => ! empty($r['invented']));

        if (! empty($invented)) {
            $this->newLine();
            $this->warn('The legacy shape stores no scheduling offsets, so these were INVENTED for ' . count($invented) . ' format(s):');
            $this->warn("  motion_reveal_offset_hours   = {$revealOffset}");
            $this->warn("  prep_rooms_open_offset_hours = {$prepOffset}");
            $this->warn('They affect when motions are revealed and prep rooms open. Override with');
            $this->warn('  --motion-reveal-offset= and --prep-offset= if these are wrong.');
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run — nothing was written. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        foreach ($repairs as $repair) {
            $repair['format']->update(['phase_config' => $repair['config']]);
            $this->line("Repaired format #{$repair['format']->id} ({$repair['format']->name}).");
        }

        $this->newLine();
        $this->info('Repaired ' . count($repairs) . ' format(s).');
        $this->line('Debates on those formats can now generate phases and start.');

        return self::SUCCESS;
    }

    /** A row is healthy when deriveStages() can read everything it needs. */
    private function isHealthy(mixed $config): bool
    {
        if (! is_array($config) || array_is_list($config)) {
            return false;
        }

        foreach (['speech_time_seconds', 'has_reply_speech', 'motion_reveal_offset_hours', 'prep_rooms_open_offset_hours'] as $key) {
            if (! array_key_exists($key, $config)) {
                return false;
            }
        }

        // reply_time_seconds is only load-bearing when replies are enabled.
        return ! ($config['has_reply_speech'] ?? false)
            || array_key_exists('reply_time_seconds', $config);
    }

    /**
     * Build a valid timing object from whatever the row holds.
     *
     * @return array{config: array<string, mixed>, invented: string[]}|null
     */
    private function repair(mixed $config, float $revealOffset, float $prepOffset): ?array
    {
        $config = is_array($config) ? $config : [];
        $isList = array_is_list($config);

        $mainDurations  = [];
        $replyDurations = [];

        if ($isList) {
            foreach ($config as $phase) {
                if (! is_array($phase) || ! isset($phase['duration_seconds'])) {
                    continue;
                }

                $duration = (int) $phase['duration_seconds'];
                $isReply  = (bool) ($phase['is_reply'] ?? false)
                    || str_contains(strtolower((string) ($phase['name'] ?? '')), 'reply');

                $isReply ? $replyDurations[] = $duration : $mainDurations[] = $duration;
            }
        }

        // Prefer a value already present on the row over anything derived.
        $speech = $config['speech_time_seconds'] ?? $this->mode($mainDurations);

        if ($speech === null) {
            return null; // nothing usable — refuse to guess a speech length
        }

        $hasReply = array_key_exists('has_reply_speech', $config)
            ? (bool) $config['has_reply_speech']
            : ! empty($replyDurations);

        $reply = $config['reply_time_seconds'] ?? $this->mode($replyDurations) ?? 0;

        $invented = [];

        if (! array_key_exists('motion_reveal_offset_hours', $config)) {
            $invented[] = 'motion_reveal_offset_hours';
        }
        if (! array_key_exists('prep_rooms_open_offset_hours', $config)) {
            $invented[] = 'prep_rooms_open_offset_hours';
        }

        return [
            'config' => [
                'speech_time_seconds'          => $this->clamp((int) $speech, self::SPEECH_MIN, self::SPEECH_MAX),
                'has_reply_speech'             => $hasReply,
                // 0 when there is no reply — matches DebateFormatSeeder, and the
                // validator only bounds this field when has_reply_speech is true.
                'reply_time_seconds'           => $hasReply ? $this->clamp((int) $reply, self::REPLY_MIN, self::REPLY_MAX) : 0,
                'motion_reveal_offset_hours'   => $config['motion_reveal_offset_hours'] ?? $revealOffset,
                'prep_rooms_open_offset_hours' => $config['prep_rooms_open_offset_hours'] ?? $prepOffset,
            ],
            'invented' => $invented,
        ];
    }

    /** Most frequent value, so one odd phase cannot skew the whole format. */
    private function mode(array $values): ?int
    {
        if (empty($values)) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
