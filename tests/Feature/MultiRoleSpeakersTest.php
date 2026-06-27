<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\Team;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B4 — a 2-person team must be able to fill all 3 speaking slots, with one
 * debater covering multiple slots in ANY order (e.g. [A, B, A]).
 */
class MultiRoleSpeakersTest extends TestCase
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
            $mock->shouldReceive('startTrackEgressForParticipant')->andReturn('EG');
        });
    }

    /** @return array{0:Debate,1:User,2:User,3:User} [debate, leader, A, B] */
    private function twoPersonPropTeam(): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 60, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id' => $format->id, 'status' => 'teams-selected', 'current_stage' => 0,
            'livekit_room_name' => 'debate-mr-main', 'result_room_name' => 'debate-mr-result',
        ]);

        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team   = Team::factory()->create(['leader_id' => $leader->id, 'is_random' => false]);

        $a = $leader;
        $b = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        foreach ([$a, $b] as $u) {
            DebateParticipant::factory()->create([
                'debate_id' => $debate->id, 'user_id' => $u->id, 'team_id' => $team->id,
                'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
                'speaking_phase_order' => null,
            ]);
        }

        return [$debate, $leader, $a, $b];
    }

    public function test_two_person_team_can_fill_three_slots_with_a_duplicate(): void
    {
        [$debate, $leader, $a, $b] = $this->twoPersonPropTeam();

        // Phases must exist for live-state to carry per-stage speakers.
        foreach ($debate->format->deriveStages() as $stage) {
            DebatePhase::factory()->create([
                'debate_id' => $debate->id, 'name' => $stage['name'],
                'order_index' => $stage['order_index'], 'duration_seconds' => $stage['duration_seconds'],
                'status' => 'pending', 'is_reply' => $stage['is_reply'],
            ]);
        }

        // A speaks slots 1 & 3, B speaks slot 2 → order [A, B, A].
        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => [$a->id, $b->id, $a->id],
        ]);

        $response->assertStatus(200);

        // The ordered assignment (with the duplicate) is persisted on the debate.
        $this->assertEquals([$a->id, $b->id, $a->id], $debate->fresh()->speakerOrderFor('proposition'));

        // live-state exposes a 3-slot speaking_order even though only 2 distinct users.
        $order = collect($response->json('data.proposition.speaking_order'));
        $this->assertCount(3, $order);
        $this->assertEquals($a->id, $order[0]['user_id']);
        $this->assertEquals($b->id, $order[1]['user_id']);
        $this->assertEquals($a->id, $order[2]['user_id']);

        // Pre-stage (lobby) speaker_user_id resolves per stage from the order:
        // prop stages are 1,3,5 → slots 1,2,3 → A, B, A.
        $stages = collect($response->json('data.stages'))->keyBy('order_index');
        $this->assertEquals($a->id, $stages[1]['speaker_user_id']);
        $this->assertEquals($b->id, $stages[3]['speaker_user_id']);
        $this->assertEquals($a->id, $stages[5]['speaker_user_id']);
    }

    public function test_advancing_stages_locks_repeated_user_onto_each_of_their_phases(): void
    {
        [$debate, $leader, $a, $b] = $this->twoPersonPropTeam();

        // Opposition (so stages 2,4,6 have speakers too) — a simple 2-person opp.
        $oppTeam = Team::factory()->create(['is_random' => true]);
        $o1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $o2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        foreach ([$o1, $o2] as $u) {
            DebateParticipant::factory()->create([
                'debate_id' => $debate->id, 'user_id' => $u->id, 'team_id' => $oppTeam->id,
                'role' => 'debater', 'side' => 'opposition', 'status' => 'approved',
            ]);
        }
        $debate->update(['opp_speaker_order' => [$o1->id, $o2->id, $o1->id]]);

        // Prop order [A, B, A].
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side' => 'proposition', 'speaker_user_ids' => [$a->id, $b->id, $a->id],
        ])->assertStatus(200);

        // Phases + chair, then go live.
        foreach ($debate->format->deriveStages() as $stage) {
            DebatePhase::factory()->create([
                'debate_id' => $debate->id, 'name' => $stage['name'],
                'order_index' => $stage['order_index'], 'duration_seconds' => $stage['duration_seconds'],
                'status' => 'pending', 'is_reply' => $stage['is_reply'],
            ]);
        }
        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);
        $debate->update(['status' => 'live']);

        // Advance to stage 1, then 3, then 5 (A's three appearances are 1 & 5).
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage")->assertStatus(200);
        }

        $aParticipant = DebateParticipant::where('debate_id', $debate->id)->where('user_id', $a->id)->first();
        $stage1 = DebatePhase::where('debate_id', $debate->id)->where('order_index', 1)->first();
        $stage5 = DebatePhase::where('debate_id', $debate->id)->where('order_index', 5)->first();

        // The SAME participant covers both stage 1 and stage 5.
        $this->assertEquals($aParticipant->id, $stage1->participant_id);
        $this->assertEquals($aParticipant->id, $stage5->participant_id);
    }

    public function test_live_state_stages_carry_phase_id(): void
    {
        // B8 — stages[].id must be present so the FE can POST /stages/{id}/poi.
        [$debate, $leader, $a, $b] = $this->twoPersonPropTeam();
        $debate->update(['status' => 'live', 'current_stage' => 0]);

        DebatePhase::factory()->create([
            'debate_id' => $debate->id, 'name' => 'Proposition 1', 'order_index' => 1,
            'duration_seconds' => 60, 'status' => 'pending', 'is_reply' => false,
        ]);

        $response = $this->actingAs($a)->getJson("/api/debates/{$debate->id}/live-state");
        $response->assertStatus(200);

        $stage = collect($response->json('data.stages'))->firstWhere('order_index', 1);
        $this->assertArrayHasKey('id', $stage);
        $this->assertNotNull($stage['id']);
    }
}
