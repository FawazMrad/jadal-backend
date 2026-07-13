<?php

namespace App\Services;

use App\Models\DebatePhase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Backfills debate_phases.speech_text from the already-recorded per-stage
 * audio (debate_phases.audio_url), via a local Whisper CLI installation.
 * Fully synchronous, manually invoked (see TranscribeDebatePhases) — this
 * project has no queue/job infrastructure (QUEUE_CONNECTION=sync).
 *
 * Pipeline per phase:
 *   resolve host audio path (services.livekit's /out → services.whisper's
 *   recordings_base_path) → normalize loudness with ffmpeg → transcribe the
 *   normalized copy with Whisper (--output_format json) → read the "text"
 *   field → save to speech_text → delete both temp files.
 *
 * The original recording under recordings_base_path is never read for
 * writing and never deleted — only the temp normalized copy + temp JSON
 * output (both under storage/app/whisper-temp/{debate_id}/) are cleaned up.
 */
class TranscriptionService
{
    private const FFMPEG_TIMEOUT_SECONDS = 300;
    private const WHISPER_TIMEOUT_SECONDS = 1800;
    private const GROQ_TIMEOUT_SECONDS = 30;

    /** Dedupe the "no API key configured" warning within one process — matters for the batch backfill command, which may call this once per phase in a single run. */
    private static bool $missingGroqKeyWarned = false;

    /** @return string one of: 'succeeded' | 'failed' | 'skipped_no_file' */
    public function transcribePhase(DebatePhase $phase): string
    {
        $context = ['debate_id' => $phase->debate_id, 'phase_id' => $phase->id];

        $hostPath = $this->resolveHostPath((string) $phase->audio_url);
        if (! is_file($hostPath)) {
            Log::warning('Transcription skipped — audio file not found on disk', $context + [
                'audio_url'     => $phase->audio_url,
                'resolved_path' => $hostPath,
            ]);

            return 'skipped_no_file';
        }

        $tempDir        = storage_path("app/whisper-temp/{$phase->debate_id}");
        $normalizedPath = "{$tempDir}/phase-{$phase->id}-normalized.mp3";
        $jsonPath       = "{$tempDir}/phase-{$phase->id}-normalized.json";

        try {
            File::ensureDirectoryExists($tempDir);

            if (! $this->normalizeAudio($hostPath, $normalizedPath, $context)) {
                return 'failed';
            }

            if (! $this->runWhisper($normalizedPath, $tempDir, $context)) {
                return 'failed';
            }

            $text = $this->extractText($jsonPath, $context);
            if ($text === null) {
                return 'failed';
            }

            $phase->update(['speech_text' => $text]);

            // Best-effort cleanup layer — never blocks/fails the transcription
            // itself. speech_text (raw) is already saved regardless of what
            // happens below; speech_text_cleaned simply stays null on any
            // Groq failure (already logged inside the method itself).
            $cleaned = $this->cleanTranscriptWithGroq($text);
            if ($cleaned !== null) {
                $phase->update(['speech_text_cleaned' => $cleaned]);

                Log::info('Debate phase transcript cleaned via Groq', $context + [
                    'raw_length'     => mb_strlen($text),
                    'cleaned_length' => mb_strlen($cleaned),
                ]);
            }

            Log::info('Debate phase transcribed successfully', $context + [
                'audio_path' => $hostPath,
                'text_length' => mb_strlen($text),
            ]);

            return 'succeeded';
        } catch (\Throwable $e) {
            Log::error('Transcription failed — unexpected exception', $context + [
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        } finally {
            $this->cleanup($normalizedPath, $jsonPath);
        }
    }

    /**
     * Fires off a fully detached, backgrounded OS process running
     * `debates:transcribe-single-phase {phaseId}` and returns immediately —
     * the caller (the egress webhook) must never block on transcription.
     *
     * System-wide only-one-Whisper-at-a-time enforcement happens INSIDE that
     * command via a blocking flock(), not here — this method may be called
     * many times in quick succession (one per finished stage); each spawned
     * process just queues up at the OS level waiting for the lock.
     *
     * Wrapped in `nice -n 19` (lowest scheduling priority) and
     * `cpulimit -l 250` (hard cap at 2.5 cores) so a live Whisper run never
     * competes with LiveKit/Nginx/DB traffic for CPU during a live debate.
     * `nohup` + a trailing `&` detach it so it survives after this PHP
     * request (and the PHP-FPM worker handling it) finishes.
     */
    public function dispatchBackgroundTranscription(DebatePhase $phase): void
    {
        $phaseId = (int) $phase->id;
        $artisan = base_path('artisan');
        $logPath = storage_path('logs/whisper-background.log');

        $command = sprintf(
            'nohup nice -n 19 cpulimit -l 250 -- php %s debates:transcribe-single-phase %s >> %s 2>&1 < /dev/null &',
            escapeshellarg($artisan),
            escapeshellarg((string) $phaseId),
            escapeshellarg($logPath)
        );

        Log::info('Dispatching background transcription', [
            'phase_id' => $phaseId,
            'command'  => $command,
        ]);

        shell_exec($command);
    }

    /**
     * "/out/113/stage-1-30.mp3" -> "{recordings_base_path}/113/stage-1-30.mp3"
     * (replaces the leading in-container "/out" with the configured host base).
     */
    private function resolveHostPath(string $audioUrl): string
    {
        $base = rtrim((string) config('services.whisper.recordings_base_path', '/var/recordings'), '/');

        if (str_starts_with($audioUrl, '/out/')) {
            return $base . substr($audioUrl, strlen('/out'));
        }

        // Not the expected "/out/..." shape written by egress — return as-is
        // so the is_file() check above fails cleanly rather than guessing.
        return $audioUrl;
    }

    /**
     * Best-effort Groq cleanup pass over a raw Whisper transcript — removes
     * ASR hallucinations (repeated filler phrases, stray non-Arabic noise)
     * while preserving the speaker's actual content. Returns null (never
     * throws) on any failure: missing API key, network error, non-2xx
     * response, or an unexpected response shape — the caller always keeps
     * the raw speech_text regardless of what this returns.
     */
    private function cleanTranscriptWithGroq(string $rawText): ?string
    {
        $apiKey = (string) config('services.groq.api_key');

        if ($apiKey === '') {
            if (! self::$missingGroqKeyWarned) {
                Log::warning('Groq transcript cleanup skipped — GROQ_API_KEY is not configured');
                self::$missingGroqKeyWarned = true;
            }

            return null;
        }

        $model = (string) config('services.groq.model', 'openai/gpt-oss-20b');
        $prompt = <<<PROMPT
        This is a raw speech-to-text transcript of an Arabic debate speech, generated by an automatic speech recognition system. The transcript may contain: (1) repeated hallucinated filler phrases (e.g. phrases about subscribing to a channel, or repeated words like "thank you") — remove these entirely; (2) garbled or mis-transcribed word fragments, including stray non-Arabic-looking characters mixed into what was actually spoken Arabic — these are transcription errors, not real words the speaker said, so use the surrounding Arabic context to infer and correct them to the most likely actual Arabic word or phrase the speaker said; (3) minor grammatical/word-boundary errors — fix these naturally.

        Be assertive about fixing garbled fragments using context — don't leave obviously broken word-fragments as-is just because you're not 100% certain of the exact original word. However, never invent new claims, arguments, or information that go beyond what the surrounding context supports, and never change the speaker's actual meaning, stance, or the substance of what they said. If a fragment is too corrupted to infer anything reasonable from context, you may omit just that fragment rather than guess wildly.

        Return ONLY the cleaned Arabic text, with no preamble, no explanation, no markdown — just the cleaned transcript itself.

        Raw transcript:
        {$rawText}
        PROMPT;

        try {
            $response = Http::timeout(self::GROQ_TIMEOUT_SECONDS)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model'            => $model,
                    'messages'         => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens'       => 4096,
                    'reasoning_effort' => 'medium',
                ]);

            if (! $response->successful()) {
                Log::error('Groq transcript cleanup failed — non-2xx response', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            $cleaned = $response->json('choices.0.message.content');

            if (! is_string($cleaned) || trim($cleaned) === '') {
                Log::error('Groq transcript cleanup failed — unexpected response shape', [
                    'body' => $response->body(),
                ]);

                return null;
            }

            return trim($cleaned);
        } catch (\Throwable $e) {
            Log::error('Groq transcript cleanup failed — exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function normalizeAudio(string $inputPath, string $outputPath, array $context): bool
    {
        $result = Process::timeout(self::FFMPEG_TIMEOUT_SECONDS)->run([
            'ffmpeg', '-y', '-i', $inputPath,
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',
            $outputPath,
        ]);

        if (! $result->successful()) {
            Log::error('Transcription failed — ffmpeg normalization error', $context + [
                'exit_code' => $result->exitCode(),
                'error'     => trim($result->errorOutput()),
            ]);

            return false;
        }

        return true;
    }

    private function runWhisper(string $normalizedPath, string $outputDir, array $context): bool
    {
        $whisperBin = (string) config('services.whisper.binary_path');

        $result = Process::timeout(self::WHISPER_TIMEOUT_SECONDS)->run([
            $whisperBin, $normalizedPath,
            '--language', 'ar',
            '--output_format', 'json',
            '--output_dir', $outputDir,
        ]);

        if (! $result->successful()) {
            Log::error('Transcription failed — Whisper error', $context + [
                'exit_code' => $result->exitCode(),
                'error'     => trim($result->errorOutput()),
            ]);

            return false;
        }

        return true;
    }

    private function extractText(string $jsonPath, array $context): ?string
    {
        if (! is_file($jsonPath)) {
            Log::error('Transcription failed — Whisper JSON output not found', $context + [
                'expected_path' => $jsonPath,
            ]);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        $text    = is_array($decoded) ? ($decoded['text'] ?? null) : null;

        if (! is_string($text) || trim($text) === '') {
            Log::error('Transcription failed — Whisper JSON had no usable "text" field', $context + [
                'json_path' => $jsonPath,
            ]);

            return null;
        }

        return trim($text);
    }

    /** Deletes only the temp normalized-audio copy + temp JSON output — never the original recording. */
    private function cleanup(string $normalizedPath, string $jsonPath): void
    {
        foreach ([$normalizedPath, $jsonPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
