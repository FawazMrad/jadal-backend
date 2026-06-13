<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LobbyTokenTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            $mock->shouldReceive('generateRoomToken')->andReturnUsing(function (...$args) {
                $this->captured = [
                    'room'          => $args[0],
                    'identity'      => $args[1],
                    'canPublish'    => $args[2],
                    'canSubscribe'  => $args[3],
                    'canPublishData' => $args[4],
                ];
                return 'fake.jwt.token';
            });
        });
    }

    private function liveDebate(int $currentStage): Debate
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        return Debate::factory()->create([
            'format_id'         => $format->id,
            'status'            => 'live',
            'current_stage'     => $currentStage,
            'livekit_room_name' => 'debate-lobby-main',
        ]);
    }

    public function test_viewer_can_publish_in_lobby_stage_zero(): void
    {
        $debate = $this->liveDebate(0);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']); // not a participant

        $response = $this->actingAs($viewer)->getJson("/api/debates/{$debate->id}/token?room=main");

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_in_room', 'viewer');
        $this->assertTrue($this->captured['canPublish']);
        $this->assertTrue($this->captured['canSubscribe']);
    }

    public function test_viewer_is_subscribe_only_during_debate_stage(): void
    {
        $debate = $this->liveDebate(1);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']); // not a participant

        $response = $this->actingAs($viewer)->getJson("/api/debates/{$debate->id}/token?room=main");

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_in_room', 'viewer');
        $this->assertFalse($this->captured['canPublish']);
        $this->assertTrue($this->captured['canSubscribe']);
    }

    public function test_main_room_not_joinable_before_live(): void
    {
        $debate = $this->liveDebate(0);
        $debate->update(['status' => 'scheduled']);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($viewer)->getJson("/api/debates/{$debate->id}/token?room=main");

        $response->assertStatus(403);
    }
}
