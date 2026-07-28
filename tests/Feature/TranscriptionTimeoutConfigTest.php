<?php

namespace Tests\Feature;

use App\Services\TranscriptionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the transcription process-timeout wiring.
 *
 * Production reported ffmpeg/whisper dying at "the timeout of 120 seconds" —
 * a value this codebase never sets. These tests pin the effective timeouts to
 * the documented values and prove the config keys are spelled correctly: a
 * typo in `services.whisper.timeout` would silently fall back to the constant
 * and the env override would appear to "do nothing", which is exactly the kind
 * of silent mismatch that made the original 120 so hard to chase.
 */
class TranscriptionTimeoutConfigTest extends TestCase
{
    private function effective(string $method): int
    {
        $m = new ReflectionMethod(TranscriptionService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(app(TranscriptionService::class));
    }

    public function test_defaults_are_the_documented_values_and_never_120(): void
    {
        $this->assertSame(300, $this->effective('ffmpegTimeout'));
        $this->assertSame(1800, $this->effective('whisperTimeout'));

        $this->assertNotSame(120, $this->effective('ffmpegTimeout'));
        $this->assertNotSame(120, $this->effective('whisperTimeout'));
    }

    public function test_config_overrides_are_actually_honoured(): void
    {
        config([
            'services.whisper.ffmpeg_timeout' => 45,
            'services.whisper.timeout'        => 900,
        ]);

        $this->assertSame(45, $this->effective('ffmpegTimeout'));
        $this->assertSame(900, $this->effective('whisperTimeout'));
    }

    /** Laravel's PendingProcess default is 60 — proves we never silently inherit it. */
    public function test_effective_timeouts_are_not_the_laravel_default(): void
    {
        $this->assertNotSame(60, $this->effective('ffmpegTimeout'));
        $this->assertNotSame(60, $this->effective('whisperTimeout'));
    }
}
