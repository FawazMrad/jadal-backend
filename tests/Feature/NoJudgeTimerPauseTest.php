<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\User;
use App\Services\LiveKitService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * When the last judge leaves the MAIN room during an active speech the
 * server auto-pauses the timer (no client authority is left to trigger it).
 */
class NoJudgeTimerPauseTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'APItestkey';
    private const SECRET = 'livekit-test-secret-0123456789-abcdefgh';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.livekit.key' => self::KEY, 'services.livekit.secret' => self::SECRET]);
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    private function signedToken(string $body): string
    {
        return JWT::encode([
            'iss'    => self::KEY,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], self::SECRET, 'HS256');
    }

    private function postLeft(Debate $debate, int $userId): TestResponse
    {
        $body = json_encode([
            'event'       => 'participant_left',
            'room'        => ['name' => $debate->livekit_room_name],
            'participant' => ['identity' => (string) $userId],
        ]);

        return $this->call('POST', '/api/livekit/webhook', [], [], [], [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_ACCEPT'        => 'application/json',
            'HTTP_AUTHORIZATION' => $this->signedToken($body),
        ], $body);
    }

    private function activeSpeechDebate(): Debate
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 480, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id' => $format->id, 'status' => 'live', 'current_stage' => 2,
            'livekit_room_name' => 'debate-nojudge-main',
        ]);

        DebatePhase::factory()->create([
            'debate_id' => $debate->id, 'order_index' => 2, 'name' => 'Opposition 1',
            'status' => 'active', 'is_reply' => false, 'started_at' => now()->subSeconds(40),
        ]);

        return $debate;
    }

    private function judge(Debate $debate, int $order, bool $chair): User
    {
        $u = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $u->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => $chair, 'judge_order' => $order, 'is_attended' => true,
        ]);
        return $u;
    }

    public function test_last_judge_leaving_pauses_the_timer(): void
    {
        $debate = $this->activeSpeechDebate();
        $chair  = $this->judge($debate, 1, true); // only judge

        $this->postLeft($debate, $chair->id)->assertStatus(200);

        $debate->refresh();
        $this->assertTrue($debate->timer_is_paused);
        $this->assertGreaterThanOrEqual(39, $debate->timer_paused_elapsed_seconds);
    }

    public function test_timer_keeps_running_while_a_judge_remains(): void
    {
        $debate = $this->activeSpeechDebate();
        $this->judge($debate, 1, true);       // chair stays
        $panel = $this->judge($debate, 2, false);

        // A panel judge leaves but the chair is still present → no pause.
        $this->postLeft($debate, $panel->id)->assertStatus(200);

        $debate->refresh();
        $this->assertFalse($debate->timer_is_paused);
    }
}
