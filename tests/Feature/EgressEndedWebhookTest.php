<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebatePhase;
use App\Services\TranscriptionService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Regression cover for the egress_ended payload-shape bug: LiveKit nests
 * egress_id / file_results under `egress_info` (WebhookEvent.egress_info,
 * field 9), but the handler used to read them from the TOP level, so it
 * silently returned on every webhook and audio_url was never written.
 *
 * The payloads below mirror the vendored protobuf definitions exactly
 * (Livekit\WebhookEvent → Livekit\EgressInfo → Livekit\FileInfo).
 */
class EgressEndedWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'APItestkey';
    private const SECRET = 'livekit-test-secret-0123456789-abcdefgh';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.livekit.key' => self::KEY, 'services.livekit.secret' => self::SECRET]);
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        $token = JWT::encode([
            'iss'    => self::KEY,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], self::SECRET, 'HS256');

        return $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => $token,
        ], $body);
    }

    private function phaseWithEgress(string $egressId): DebatePhase
    {
        return DebatePhase::factory()->create([
            'debate_id' => Debate::factory(),
            'egress_id' => $egressId,
            'audio_url' => null,
        ]);
    }

    /** The transcription dispatch shells out to a real OS process — never in tests. */
    private function expectTranscriptionDispatched(int $times = 1): void
    {
        $mock = Mockery::mock(TranscriptionService::class);
        $mock->shouldReceive('dispatchBackgroundTranscription')->times($times);
        $this->app->instance(TranscriptionService::class, $mock);
    }

    /** The REAL shape LiveKit sends: everything nested under egress_info. */
    public function test_real_livekit_payload_sets_audio_url(): void
    {
        $this->expectTranscriptionDispatched();
        $phase = $this->phaseWithEgress('EG_realshape123');

        $this->postWebhook([
            'event'       => 'egress_ended',
            'id'          => 'EV_abc',
            'egress_info' => [
                'egress_id'    => 'EG_realshape123',
                'room_name'    => 'debate-xyz-main',
                'status'       => 'EGRESS_COMPLETE',
                'file_results' => [[
                    'filename' => '/out/113/stage-1-30.mp3',
                    'size'     => 483920,
                    'duration' => 92000000000,
                ]],
            ],
        ])->assertStatus(200);

        $this->assertSame('/out/113/stage-1-30.mp3', $phase->fresh()->audio_url);
    }

    /** protojson's default lowerCamelCase spelling must work identically. */
    public function test_lower_camel_case_payload_sets_audio_url(): void
    {
        $this->expectTranscriptionDispatched();
        $phase = $this->phaseWithEgress('EG_camel456');

        $this->postWebhook([
            'event'      => 'egress_ended',
            'egressInfo' => [
                'egressId'    => 'EG_camel456',
                'roomName'    => 'debate-xyz-main',
                'status'      => 'EGRESS_COMPLETE',
                'fileResults' => [['filename' => '/out/114/stage-2-31.mp3']],
            ],
        ])->assertStatus(200);

        $this->assertSame('/out/114/stage-2-31.mp3', $phase->fresh()->audio_url);
    }

    /** FileInfo.location is the cloud-upload fallback when filename is absent. */
    public function test_falls_back_to_location_when_filename_absent(): void
    {
        $this->expectTranscriptionDispatched();
        $phase = $this->phaseWithEgress('EG_loc789');

        $this->postWebhook([
            'event'       => 'egress_ended',
            'egress_info' => [
                'egress_id'    => 'EG_loc789',
                'status'       => 'EGRESS_COMPLETE',
                'file_results' => [['location' => 'https://s3.example.com/rec/stage-3.mp3']],
            ],
        ])->assertStatus(200);

        $this->assertSame('https://s3.example.com/rec/stage-3.mp3', $phase->fresh()->audio_url);
    }

    /** A flattened envelope still works — defensive top-level fallback. */
    public function test_top_level_payload_still_supported(): void
    {
        $this->expectTranscriptionDispatched();
        $phase = $this->phaseWithEgress('EG_toplevel999');

        $this->postWebhook([
            'event'        => 'egress_ended',
            'egress_id'    => 'EG_toplevel999',
            'file_results' => [['filename' => '/out/115/stage-4-32.mp3']],
        ])->assertStatus(200);

        $this->assertSame('/out/115/stage-4-32.mp3', $phase->fresh()->audio_url);
    }

    public function test_missing_egress_id_logs_warning_and_does_not_throw(): void
    {
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'no egress_id found'));

        $this->postWebhook([
            'event'       => 'egress_ended',
            'egress_info' => ['status' => 'EGRESS_FAILED'],
        ])->assertStatus(200);
    }

    public function test_unmatched_egress_id_logs_warning_with_the_searched_id(): void
    {
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'no debate_phases row matches')
                && $context['searched_egress_id'] === 'EG_nosuchphase');

        $this->postWebhook([
            'event'       => 'egress_ended',
            'egress_info' => [
                'egress_id'    => 'EG_nosuchphase',
                'file_results' => [['filename' => '/out/x.mp3']],
            ],
        ])->assertStatus(200);
    }

    public function test_matched_phase_without_file_results_logs_warning_and_leaves_audio_url_null(): void
    {
        $phase = $this->phaseWithEgress('EG_nofiles');

        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'no file path in the payload')
                && $context['egress_id'] === 'EG_nofiles');

        $this->postWebhook([
            'event'       => 'egress_ended',
            'egress_info' => [
                'egress_id'    => 'EG_nofiles',
                'status'       => 'EGRESS_FAILED',
                'file_results' => [],
            ],
        ])->assertStatus(200);

        $this->assertNull($phase->fresh()->audio_url);
    }

    /**
     * Proves the ROOT CAUSE: with the pre-fix field paths, LiveKit's real
     * nested payload yields no egress_id at all — which is why the handler
     * silently returned and audio_url was never written.
     */
    public function test_documents_the_root_cause_top_level_keys_absent_in_real_payload(): void
    {
        $realPayload = [
            'event'       => 'egress_ended',
            'egress_info' => [
                'egress_id'    => 'EG_realshape123',
                'file_results' => [['filename' => '/out/113/stage-1-30.mp3']],
            ],
        ];

        // What the old code read:
        $this->assertNull($realPayload['egress_id'] ?? null);
        $this->assertSame([], $realPayload['file_results'] ?? $realPayload['outputs'] ?? []);

        // Where the data actually lives:
        $this->assertSame('EG_realshape123', $realPayload['egress_info']['egress_id']);
    }
}
