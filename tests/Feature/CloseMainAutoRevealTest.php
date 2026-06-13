<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseMainAutoRevealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('deleteRoomIfExists')->andReturnNull();
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    private function completedDebateWithChair(): array
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
            'status'            => 'completed',
            'livekit_room_name' => 'debate-cm-main',
            'result_room_name'  => 'debate-cm-result',
            'result_revealed_at' => null,
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        return [$debate, $chair];
    }

    public function test_closing_main_with_result_submitted_auto_reveals(): void
    {
        [$debate, $chair] = $this->completedDebateWithChair();
        DebateResult::factory()->create([
            'debate_id' => $debate->id,
            'judge_id'  => $chair->id,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-main");

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertNotNull($debate->result_revealed_at);
    }

    public function test_closing_main_without_result_does_not_reveal(): void
    {
        [$debate, $chair] = $this->completedDebateWithChair();

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-main");

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertNull($debate->result_revealed_at);
    }

    public function test_non_chair_cannot_close_main(): void
    {
        [$debate, $chair] = $this->completedDebateWithChair();
        $other = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $other->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->actingAs($other)
            ->postJson("/api/debates/{$debate->id}/close-main")
            ->assertStatus(403);
    }
}
