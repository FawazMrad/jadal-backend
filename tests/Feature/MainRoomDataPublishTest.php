<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every real participant in the main room must be granted
 * canPublishData (the 5th arg to generateRoomToken). Without it the SFU drops
 * that participant's data-channel messages, so realtime signals (a debater's
 * POI, the chair's timer/lobby/mute) never reach the other devices.
 */
class MainRoomDataPublishTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            // generateRoomToken(roomName, identity, canPublish, canSubscribe, canPublishData, ...)
            $mock->shouldReceive('generateRoomToken')->andReturnUsing(function (...$args) {
                $this->captured = ['canPublishData' => $args[4]];
                return 'fake.jwt.token';
            });
        });
    }

    private function liveDebate(int $currentStage): Debate
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 300, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        return Debate::factory()->create([
            'format_id' => $format->id,
            'status' => 'live',
            'current_stage' => $currentStage,
            'livekit_room_name' => 'debate-main-room',
        ]);
    }

    private function dataGrantFor(Debate $debate, User $user): bool
    {
        $this->actingAs($user)
            ->getJson("/api/debates/{$debate->id}/token?room=main")
            ->assertStatus(200);

        return (bool) $this->captured['canPublishData'];
    }

    public function test_debater_can_publish_data_in_debate_mode(): void
    {
        $debate  = $this->liveDebate(2); // stage > 0 = debate mode
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
            'speaking_phase_order' => 1,
        ]);

        $this->assertTrue($this->dataGrantFor($debate, $debater));
    }

    public function test_panel_judge_can_publish_data_in_debate_mode(): void
    {
        $debate = $this->liveDebate(2);
        $panel  = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panel->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->assertTrue($this->dataGrantFor($debate, $panel));
    }

    public function test_lobby_participant_can_publish_data(): void
    {
        $debate  = $this->liveDebate(0); // lobby
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => 'opposition', 'status' => 'approved',
            'speaking_phase_order' => 1,
        ]);

        $this->assertTrue($this->dataGrantFor($debate, $debater));
    }

    public function test_viewer_cannot_publish_data(): void
    {
        $debate = $this->liveDebate(2);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        // No participant row → pure spectator.

        $this->assertFalse($this->dataGrantFor($debate, $viewer));
    }
}
