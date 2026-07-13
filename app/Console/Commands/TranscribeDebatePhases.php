<?php

namespace App\Console\Commands;

use App\Models\DebatePhase;
use App\Services\TranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manually-invoked backfill: transcribes every debate_phases row that has a
 * recorded audio_url but no speech_text yet. NOT scheduled — run by hand
 * while testing (no entry added to routes/console.php).
 */
class TranscribeDebatePhases extends Command
{
    protected $signature = 'debates:transcribe-phases';

    protected $description = 'Transcribe debate_phases audio (audio_url) into speech_text via a local Whisper install.';

    /** Rows are pulled and processed this many at a time (chunkById — safe even though speech_text, part of the filter, is written mid-loop). */
    private const BATCH_SIZE = 10;

    public function handle(TranscriptionService $service): int
    {
        $total = $this->pendingQuery()->count();

        if ($total === 0) {
            $this->info('No pending phases to transcribe (audio_url set, speech_text empty).');

            return self::SUCCESS;
        }

        $this->info("Found {$total} phase(s) pending transcription. Processing in batches of " . self::BATCH_SIZE . '...');
        $this->newLine();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skippedNoFile = 0;

        $this->pendingQuery()
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($phases) use ($service, &$processed, &$succeeded, &$failed, &$skippedNoFile) {
                foreach ($phases as $phase) {
                    $processed++;
                    $outcome = $service->transcribePhase($phase);

                    match ($outcome) {
                        'succeeded'       => $succeeded++,
                        'skipped_no_file' => $skippedNoFile++,
                        default           => $failed++,
                    };

                    $this->line("  [debate {$phase->debate_id} / phase {$phase->id}] {$outcome}");
                }
            });

        $this->newLine();
        $this->info('Transcription run complete.');
        $this->table(
            ['Processed', 'Succeeded', 'Failed', 'Skipped (no file)'],
            [[$processed, $succeeded, $failed, $skippedNoFile]]
        );

        return self::SUCCESS;
    }

    private function pendingQuery(): Builder
    {
        return DebatePhase::whereNotNull('audio_url')->whereNull('speech_text');
    }
}
