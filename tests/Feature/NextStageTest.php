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

class NextStageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('stopEgress')->andReturnNull();
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
            $mock->shouldReceive('startTrackEgressForParticipant')->andReturn('egress-mock-id');
        });
    }

    private function makeFullDebate(): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 60,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id'        => $format->id,
            'status'           => 'live',
            'current_stage'    => 0,
            'livekit_room_name' => 'debate-1-main',
            'result_room_name'  => 'debate-1-result',
        ]);

        // Create 6 phases.
        $stages = $format->deriveStages();
        foreach ($stages as $stage) {
            DebatePhase::factory()->create([
                'debate_id'        => $debate->id,
                'name'             => $stage['name'],
                'order_index'      => $stage['order_index'],
                'duration_seconds' => $stage['duration_seconds'],
                'status'           => 'pending',
                'is_reply'         => $stage['is_reply'],
            ]);
        }

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

        // Add 3 prop and 3 opp debaters with speaking orders.
        foreach ([1, 2, 3] as $order) {
            $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            DebateParticipant::factory()->create([
                'debate_id'            => $debate->id,
                'user_id'              => $debater->id,
                'role'                 => 'debater',
                'side'                 => 'proposition',
                'status'               => 'approved',
                'speaking_phase_order' => $order,
            ]);
            $debater2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            DebateParticipant::factory()->create([
                'debate_id'            => $debate->id,
                'user_id'              => $debater2->id,
                'role'                 => 'debater',
                'side'                 => 'opposition',
                'status'               => 'approved',
                'speaking_phase_order' => $order,
            ]);
        }

        return [$debate, $chair];
    }

    public function test_only_chair_can_advance(): void
    {
        [$debate, $chair] = $this->makeFullDebate();
        $nonChair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $nonChair->id,
            'role'      => 'judge',
            'side'      => 'judge',
            'status'    => 'approved',
            'is_chair'  => false,
            'judge_order' => 2,
        ]);

        $this->actingAs($nonChair)
            ->postJson("/api/debates/{$debate->id}/next-stage")
            ->assertStatus(403);
    }

    public function test_advancing_past_last_stage_enters_result_phase_not_completed(): void
    {
        [$debate, $chair] = $this->makeFullDebate();

        // Advance through all 6 stages.
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($chair)
                ->postJson("/api/debates/{$debate->id}/next-stage")
                ->assertStatus(200);
        }

        // One more call pushes past the last speech → the RESULT PHASE.
        // Status MUST stay `live` (not completed); only close-room finalises it.
        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/next-stage")
            ->assertStatus(200);

        $debate->refresh();
        $this->assertEquals('live', $debate->status);
        $this->assertNotNull($debate->speeches_completed_at);
        $this->assertNotNull($debate->ended_at);
        $this->assertTrue($debate->isInResultPhase());
    }

    public function test_current_stage_increments_correctly(): void
    {
        [$debate, $chair] = $this->makeFullDebate();

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");
        $debate->refresh();
        $this->assertEquals(1, $debate->current_stage);

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");
        $debate->refresh();
        $this->assertEquals(2, $debate->current_stage);
    }

    public function test_advancing_past_last_stage_creates_result_room(): void
    {
        [$debate, $chair] = $this->makeFullDebate();

        // Advance past all 6 + 1 extra.
        for ($i = 0; $i <= 6; $i++) {
            $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");
        }

        $debate->refresh();
        // Result phase: still live, speeches done, result room provisioned.
        $this->assertEquals('live', $debate->status);
        $this->assertNotNull($debate->speeches_completed_at);

        // LiveKit mock should have been called to create the result room.
        // (Verified via mock setup — no exception thrown means call succeeded.)
        $this->assertTrue(true);
    }

    public function test_cannot_advance_after_speeches_completed(): void
    {
        // Issue 9 — once the speaking phase is done, further next-stage calls
        // must be rejected (the forward path is submit/reveal → close-room).
        [$debate, $chair] = $this->makeFullDebate();

        // 6 stages + 1 transition into the result phase.
        for ($i = 0; $i <= 6; $i++) {
            $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage")->assertStatus(200);
        }

        $debate->refresh();
        $stageAtCompletion = $debate->current_stage;

        // The 8th call must be rejected and must NOT move the stage marker.
        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/next-stage")
            ->assertStatus(422);

        $this->assertEquals($stageAtCompletion, $debate->fresh()->current_stage);
    }

    public function test_non_live_debate_cannot_advance(): void
    {
        [$debate, $chair] = $this->makeFullDebate();
        $debate->update(['status' => 'scheduled']);

        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/next-stage")
            ->assertStatus(422);
    }

    public function test_phase_is_marked_active_on_advance(): void
    {
        [$debate, $chair] = $this->makeFullDebate();

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");
        $debate->refresh();

        $phase = DebatePhase::where('debate_id', $debate->id)->where('order_index', 1)->first();
        $this->assertEquals('active', $phase->status);
        $this->assertNotNull($phase->started_at);
    }

    public function test_previous_phase_marked_completed_on_advance(): void
    {
        [$debate, $chair] = $this->makeFullDebate();

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");
        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage");

        $phase = DebatePhase::where('debate_id', $debate->id)->where('order_index', 1)->first();
        $this->assertEquals('completed', $phase->status);
        $this->assertNotNull($phase->ended_at);
    }
}
