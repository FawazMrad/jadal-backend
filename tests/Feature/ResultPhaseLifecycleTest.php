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

/**
 * B1/B2 — once speeches finish the debate enters the RESULT PHASE: status stays
 * `live`, the main room stays joinable (rejoin works), the result room opens for
 * judges, and the chair submits + reveals — all before close-room finalises it.
 */
class ResultPhaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            $mock->shouldReceive('deleteRoomIfExists')->andReturnNull();
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
            $mock->shouldReceive('generateRoomToken')->andReturn('fake.jwt');
        });
    }

    /** @return array{0:Debate,1:User} [debate (result phase), chair] */
    private function resultPhaseDebate(): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 300, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id' => $format->id,
            'status' => 'live',
            'current_stage' => 7,                 // > 6 total = past the last speech
            'speeches_completed_at' => now(),
            'livekit_room_name' => 'debate-rp-main',
            'result_room_name' => 'debate-rp-result',
        ]);

        foreach (range(1, 6) as $i) {
            DebatePhase::factory()->create([
                'debate_id' => $debate->id, 'name' => "Stage {$i}", 'order_index' => $i,
                'status' => 'completed', 'is_reply' => false,
            ]);
        }

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1, 'is_attended' => true,
        ]);

        return [$debate, $chair];
    }

    public function test_live_state_signals_result_phase_while_still_live(): void
    {
        [$debate, $chair] = $this->resultPhaseDebate();

        $response = $this->actingAs($chair)->getJson("/api/debates/{$debate->id}/live-state");
        $response->assertStatus(200);

        $response->assertJsonPath('data.debate.status', 'live');
        $this->assertNotNull($response->json('data.debate.speeches_completed_at'));
        // Main room stays open/joinable; result room opens for the judge.
        $this->assertTrue($response->json('data.rooms.main.open'));
        $this->assertTrue($response->json('data.rooms.main.joinable_for_me'));
        $this->assertTrue($response->json('data.rooms.result.open'));
        $this->assertTrue($response->json('data.rooms.result.joinable_for_me'));
    }

    public function test_main_room_token_still_works_in_result_phase(): void
    {
        // B2 — rejoining the main room must keep working through the result phase.
        [$debate, $chair] = $this->resultPhaseDebate();

        $response = $this->actingAs($chair)->getJson("/api/debates/{$debate->id}/token?room=main");
        $response->assertStatus(200);
    }

    public function test_chair_can_submit_result_in_result_phase(): void
    {
        [$debate, $chair] = $this->resultPhaseDebate();

        $stageScores = [];
        foreach (range(1, 6) as $i) {
            $stageScores[] = ['stage_order' => $i, 'score' => 70 + $i];
        }

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result", [
            'winning_side' => 'proposition',
            'stage_scores' => $stageScores,
        ]);

        $response->assertStatus(201);
        $this->assertTrue($debate->fresh()->result()->exists());
        // Submitting does NOT finalise the debate — still live.
        $this->assertEquals('live', $debate->fresh()->status);
    }

    public function test_cannot_submit_before_speeches_complete(): void
    {
        [$debate, $chair] = $this->resultPhaseDebate();
        $debate->update(['speeches_completed_at' => null, 'current_stage' => 3]);

        $stageScores = [];
        foreach (range(1, 6) as $i) {
            $stageScores[] = ['stage_order' => $i, 'score' => 70 + $i];
        }

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result", [
            'winning_side' => 'proposition',
            'stage_scores' => $stageScores,
        ])->assertStatus(422);
    }
}
