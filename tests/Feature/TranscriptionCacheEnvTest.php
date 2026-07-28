<?php

namespace Tests\Feature;

use App\Services\TranscriptionService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The transcription pipeline runs as www-data, whose home (/var/www) is not
 * writable. Whisper, PyTorch and huggingface_hub all derive their cache paths
 * from HOME/XDG_CACHE_HOME/HF_HOME, so without an explicit override every new
 * cache subdirectory those libraries invent becomes a fresh production
 * permission failure.
 *
 * These tests pin the redirect: both spawned subprocesses must carry a cache
 * environment pointing inside this project's own storage tree.
 */
class TranscriptionCacheEnvTest extends TestCase
{
    /** Every root a Python/ML library might derive a cache path from. */
    private const EXPECTED_VARS = [
        'HOME',
        'XDG_CACHE_HOME',
        'HF_HOME',
        'HUGGINGFACE_HUB_CACHE',
        'TORCH_HOME',
    ];

    private function invoke(string $method, array $args): mixed
    {
        $m = new ReflectionMethod(TranscriptionService::class, $method);
        $m->setAccessible(true);

        return $m->invoke(app(TranscriptionService::class), ...$args);
    }

    public function test_every_cache_var_points_into_project_storage_not_a_home_directory(): void
    {
        $env = $this->invoke('subprocessEnv', []);
        $expected = storage_path('app/whisper-cache');

        foreach (self::EXPECTED_VARS as $var) {
            $this->assertArrayHasKey($var, $env, "{$var} must be redirected");
            $this->assertSame($expected, $env[$var], "{$var} must point into project storage");
        }

        // The whole point: nothing resolves through www-data's home.
        $this->assertStringNotContainsString('/var/www/.cache', $env['HOME']);
    }

    public function test_cache_directory_is_created_so_no_manual_chown_is_ever_needed(): void
    {
        $dir = storage_path('app/whisper-cache');
        File::deleteDirectory($dir);
        $this->assertDirectoryDoesNotExist($dir);

        $this->invoke('subprocessEnv', []);

        $this->assertDirectoryExists($dir);
    }

    public function test_whisper_subprocess_actually_receives_the_cache_environment(): void
    {
        Process::fake();

        $this->invoke('runWhisper', [
            storage_path('app/whisper-temp/x-normalized.mp3'),
            storage_path('app/whisper-temp'),
            ['debate_id' => 1, 'phase_id' => 2],
        ]);

        Process::assertRan(function (PendingProcess $process) {
            foreach (self::EXPECTED_VARS as $var) {
                if (($process->environment[$var] ?? null) !== storage_path('app/whisper-cache')) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_ffmpeg_subprocess_also_receives_the_cache_environment(): void
    {
        Process::fake();

        $this->invoke('normalizeAudio', [
            '/tmp/in.mp3',
            storage_path('app/whisper-temp/out.mp3'),
            ['debate_id' => 1, 'phase_id' => 2],
        ]);

        Process::assertRan(
            fn (PendingProcess $process) => ($process->environment['HOME'] ?? null) === storage_path('app/whisper-cache')
        );
    }

    /**
     * Symfony merges the supplied env over the parent's rather than replacing
     * it (Process.php: `$env += $this->getDefaultEnv()`). If that ever changed
     * to a replace, PATH would vanish and the whisper binary would not resolve
     * — so the override must stay strictly additive.
     */
    public function test_override_is_additive_only_and_does_not_clobber_path(): void
    {
        $env = $this->invoke('subprocessEnv', []);

        $this->assertArrayNotHasKey('PATH', $env);
        $this->assertSame(count(self::EXPECTED_VARS), count($env));
    }
}
