<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('stopEgress')->andReturnNull();
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            $mock->shouldReceive('deleteRoomIfExists')->andReturnNull();
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    private function makeMidDebate(): array
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

        $debate = Debate::factory()->create([
            'format_id'         => $format->id,
            'status'            => 'live',
            'current_stage'     => 1,
            'livekit_room_name' => 'debate-rb-main',
            'prop_room_name'    => 'debate-rb-prop',
            'opp_room_name'     => 'debate-rb-opp',
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        $speaker = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $speakerP = DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $speaker->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
            'speaking_phase_order' => 1,
        ]);

        // Active phase 1 with a recording in progress.
        DebatePhase::factory()->create([
            'debate_id'      => $debate->id,
            'name'           => 'Proposition 1',
            'order_index'    => 1,
            'status'         => 'active',
            'started_at'     => now()->subMinute(),
            'participant_id' => $speakerP->id,
            'egress_id'      => 'EG_rollback',
            'audio_url'      => '/var/recordings/x.mp4',
        ]);

        return [$debate, $chair];
    }

    public function test_chair_can_rollback_to_lobby(): void
    {
        [$debate, $chair] = $this->makeMidDebate();

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/rollback-to-lobby");

        $response->assertStatus(200);

        $debate->refresh();
        $this->assertEquals(0, $debate->current_stage);

        $phase = DebatePhase::where('debate_id', $debate->id)->where('order_index', 1)->first();
        $this->assertEquals('pending', $phase->status);
        $this->assertNull($phase->started_at);
        $this->assertNull($phase->participant_id);
        $this->assertNull($phase->egress_id);
        $this->assertNull($phase->audio_url);
    }

    public function test_non_chair_cannot_rollback(): void
    {
        [$debate, $chair] = $this->makeMidDebate();
        $other = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $other->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->actingAs($other)
            ->postJson("/api/debates/{$debate->id}/rollback-to-lobby")
            ->assertStatus(403);
    }

    public function test_cannot_rollback_when_in_lobby_already(): void
    {
        [$debate, $chair] = $this->makeMidDebate();
        $debate->update(['current_stage' => 0]);

        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/rollback-to-lobby")
            ->assertStatus(422);
    }
}
