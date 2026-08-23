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
 * Intro phase. The chair takes the room live from the open lobby:
 * live_started_at is set while current_stage stays 0 (chair welcome, no speech).
 */
class StartLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    /** @return array{0:Debate,1:User} live debate in the lobby, chair */
    private function lobbyDebate(): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 480, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id' => $format->id,
            'status' => 'live',
            'current_stage' => 0,
            'live_started_at' => null,
            'livekit_room_name' => 'debate-intro-main',
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1, 'is_attended' => true,
        ]);

        return [$debate, $chair];
    }

    public function test_chair_starts_live_into_intro(): void
    {
        [$debate, $chair] = $this->lobbyDebate();

        $res = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/start-live");
        $res->assertStatus(200);

        $debate->refresh();
        $this->assertNotNull($debate->live_started_at);
        $this->assertEquals(0, $debate->current_stage); // still pre-speech
        $this->assertTrue($debate->isInIntro());
        $this->assertNotNull($res->json('data.debate.live_started_at'));
    }

    public function test_non_chair_cannot_start_live(): void
    {
        [$debate] = $this->lobbyDebate();
        $panel = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panel->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->actingAs($panel)->postJson("/api/debates/{$debate->id}/start-live")->assertStatus(403);
    }

    public function test_cannot_start_live_after_a_speech_has_started(): void
    {
        [$debate, $chair] = $this->lobbyDebate();
        $debate->update(['current_stage' => 1]);

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/start-live")->assertStatus(422);
    }

    public function test_start_live_is_idempotent(): void
    {
        [$debate, $chair] = $this->lobbyDebate();

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/start-live")->assertStatus(200);
        $first = $debate->fresh()->live_started_at;

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/start-live")->assertStatus(200);
        $this->assertEquals($first->timestamp, $debate->fresh()->live_started_at->timestamp);
    }
}
