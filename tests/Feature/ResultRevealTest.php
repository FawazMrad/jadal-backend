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

class ResultRevealTest extends TestCase
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

    private function makeCompletedDebate(): array
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

        // Result phase: speeches are done (speeches_completed_at set) but the
        // debate is still `live` — submit/reveal happen here, before close-room.
        $debate = Debate::factory()->create([
            'format_id'       => $format->id,
            'status'          => 'live',
            'speeches_completed_at' => now(),
            'result_room_name' => 'debate-result-room',
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id'   => $debate->id,
            'user_id'     => $chair->id,
            'role'        => 'judge',
            'side'        => 'judge',
            'status'      => 'approved',
            'is_chair'    => true,
            'judge_order' => 1,
        ]);

        return [$debate, $chair];
    }

    public function test_result_hidden_before_reveal_for_non_judges(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        DebateResult::factory()->create([
            'debate_id'   => $debate->id,
            'judge_id'    => $chair->id,
            'submitted_at' => now(),
        ]);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $debater->id,
            'role'      => 'debater',
            'side'      => 'proposition',
            'status'    => 'approved',
        ]);

        $response = $this->actingAs($debater)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.result'));
    }

    public function test_result_visible_to_judge_before_reveal(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        DebateResult::factory()->create([
            'debate_id'   => $debate->id,
            'judge_id'    => $chair->id,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($chair)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.result'));
    }

    public function test_chair_can_reveal_result(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        DebateResult::factory()->create([
            'debate_id'   => $debate->id,
            'judge_id'    => $chair->id,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result/reveal");

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertNotNull($debate->result_revealed_at);
    }

    public function test_non_chair_cannot_reveal_result(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        DebateResult::factory()->create([
            'debate_id'   => $debate->id,
            'judge_id'    => $chair->id,
            'submitted_at' => now(),
        ]);

        $other = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id'   => $debate->id,
            'user_id'     => $other->id,
            'role'        => 'judge',
            'side'        => 'judge',
            'status'      => 'approved',
            'is_chair'    => false,
            'judge_order' => 2,
        ]);

        $response = $this->actingAs($other)->postJson("/api/debates/{$debate->id}/result/reveal");

        $response->assertStatus(403);
    }

    public function test_cannot_reveal_without_submitting_result_first(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result/reveal");

        $response->assertStatus(422);
    }

    public function test_cannot_reveal_twice(): void
    {
        [$debate, $chair] = $this->makeCompletedDebate();

        DebateResult::factory()->create([
            'debate_id'   => $debate->id,
            'judge_id'    => $chair->id,
            'submitted_at' => now(),
        ]);

        $debate->update(['result_revealed_at' => now()]);

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result/reveal");

        $response->assertStatus(409);
    }
}
