<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultRoomAllJudgesTest extends TestCase
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
                $this->captured = ['canPublish' => $args[2], 'canSubscribe' => $args[3]];
                return 'fake.jwt.token';
            });
        });
    }

    private function completedDebate(): Debate
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
            'format_id'        => $format->id,
            'status'           => 'completed',
            'result_room_name' => 'debate-res-room',
            'result_revealed_at' => null,
        ]);
    }

    public function test_panel_judge_can_publish_in_result_room(): void
    {
        $debate = $this->completedDebate();
        $panel  = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panel->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $response = $this->actingAs($panel)->getJson("/api/debates/{$debate->id}/token?room=result");

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_in_room', 'judge_panel');
        $this->assertTrue($this->captured['canPublish']);
    }

    public function test_chair_judge_can_publish_in_result_room(): void
    {
        $debate = $this->completedDebate();
        $chair  = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        $response = $this->actingAs($chair)->getJson("/api/debates/{$debate->id}/token?room=result");

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_in_room', 'judge_chair');
        $this->assertTrue($this->captured['canPublish']);
    }

    public function test_debater_blocked_from_result_room(): void
    {
        $debate = $this->completedDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
        ]);

        $response = $this->actingAs($debater)->getJson("/api/debates/{$debate->id}/token?room=result");

        $response->assertStatus(403);
    }
}
