<?php

namespace App\Services;

use App\Models\DebatePhase;
use Illuminate\Support\Facades\File;
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
