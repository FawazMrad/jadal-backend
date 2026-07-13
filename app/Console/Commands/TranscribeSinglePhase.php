<?php

namespace App\Console\Commands;

use App\Models\DebatePhase;
use App\Services\TranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Runs the full Whisper pipeline for exactly ONE debate_phases row. This is
 * the process TranscriptionService::dispatchBackgroundTranscription() launches
 * as a detached background OS process, immediately after
 * LiveKitWebhookController::onEgressEnded() sets a phase's audio_url.
 *
 * System-wide mutual exclusion: only one Whisper transcription may run at a
 * time, ever (RAM/CPU safety during a live debate with fast-paced stages).
 * Enforced with a BLOCKING flock() (LOCK_EX, no LOCK_NB, no timeout) on
 * storage/app/whisper-temp/transcription.lock — no queue, no Redis, no DB
 * row, just a filesystem lock file. If the lock is already held, this
 * process waits its turn; it never skips or fails because of contention.
 */
class TranscribeSinglePhase extends Command
{
    protected $signature = 'debates:transcribe-single-phase {phaseId}';

    protected $description = 'Transcribe exactly one debate_phases row via Whisper, serialized system-wide by a blocking filesystem lock.';

    public function handle(TranscriptionService $service): int
    {
        $phaseId = (int) $this->argument('phaseId');
        $phase = DebatePhase::find($phaseId);

        if (! $phase) {
            Log::error('debates:transcribe-single-phase — phase not found', ['phase_id' => $phaseId]);
            $this->error("DebatePhase {$phaseId} not found.");

            return self::FAILURE;
        }

        $lockDir = storage_path('app/whisper-temp');
        File::ensureDirectoryExists($lockDir);
        $lockPath = "{$lockDir}/transcription.lock";

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            Log::error('debates:transcribe-single-phase — could not open lock file', [
                'phase_id'  => $phaseId,
                'lock_path' => $lockPath,
            ]);
            $this->error("Could not open lock file at {$lockPath}.");

            return self::FAILURE;
        }

        $waitStartedAt = microtime(true);

        // Blocking — no LOCK_NB, no timeout. If another transcription is
        // already running anywhere, this call simply waits its turn.
        flock($handle, LOCK_EX);

        $acquiredAt    = microtime(true);
        $waitedSeconds = round($acquiredAt - $waitStartedAt, 3);

        Log::info('Transcription lock acquired', [
            'phase_id'       => $phaseId,
            'waited_seconds' => $waitedSeconds,
        ]);

        try {
            $outcome = $service->transcribePhase($phase);
            $this->info("Phase {$phaseId}: {$outcome}");

            return self::SUCCESS;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);

            Log::info('Transcription lock released', [
                'phase_id'     => $phaseId,
                'held_seconds' => round(microtime(true) - $acquiredAt, 3),
            ]);
        }
    }
}
