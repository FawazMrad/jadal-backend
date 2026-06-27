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

class ReplySpeakerSelectionTest extends TestCase
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
            $mock->shouldReceive('startTrackEgressForParticipant')->andReturn('EG_reply');
        });
    }

    private function replyFormat(): DebateFormat
    {
        return DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    private function makeTeamSelectDebate(): array
    {
        $debate = Debate::factory()->create([
            'format_id'     => $this->replyFormat()->id,
            'status'        => 'teams-selected',
            'current_stage' => 0,
        ]);

        $leader = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team   = Team::factory()->create(['leader_id' => $leader->id, 'is_random' => false]);

        $members = [];
        for ($i = 0; $i < 3; $i++) {
            $u = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            DebateParticipant::factory()->create([
                'debate_id' => $debate->id, 'user_id' => $u->id,
                'role' => 'debater', 'side' => 'proposition', 'team_id' => $team->id,
                'status' => 'approved', 'speaking_phase_order' => null,
            ]);
            $members[] = $u;
        }

        return [$debate, $leader, $members];
    }

    public function test_leader_can_pick_slot_2_as_reply_speaker(): void
    {
        [$debate, $leader, $members] = $this->makeTeamSelectDebate();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'                  => 'proposition',
            'speaker_user_ids'      => [$members[0]->id, $members[1]->id, $members[2]->id],
            'reply_speaker_user_id' => $members[1]->id, // slot 2
        ]);

        $response->assertStatus(200);

        $flagged = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $members[1]->id)
            ->first();
        $this->assertTrue((bool) $flagged->is_reply_speaker);

        // Only one reply speaker on the side.
        $count = DebateParticipant::where('debate_id', $debate->id)
            ->where('side', 'proposition')
            ->where('is_reply_speaker', true)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_live_state_exposes_reply_speaker_flag(): void
    {
        [$debate, $leader, $members] = $this->makeTeamSelectDebate();

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'                  => 'proposition',
            'speaker_user_ids'      => [$members[0]->id, $members[1]->id, $members[2]->id],
            'reply_speaker_user_id' => $members[1]->id, // slot 2
        ])->assertStatus(200);

        $response = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/live-state");
        $response->assertStatus(200);

        // The reply speaker carries is_reply_speaker = true; the others false.
        $speakers = collect($response->json('data.proposition.speakers'))
            ->keyBy(fn ($s) => $s['user']['id']);
        $this->assertTrue($speakers[$members[1]->id]['is_reply_speaker']);
        $this->assertFalse($speakers[$members[0]->id]['is_reply_speaker']);
    }

    public function test_leader_cannot_pick_slot_3_as_reply_speaker(): void
    {
        [$debate, $leader, $members] = $this->makeTeamSelectDebate();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'                  => 'proposition',
            'speaker_user_ids'      => [$members[0]->id, $members[1]->id, $members[2]->id],
            'reply_speaker_user_id' => $members[2]->id, // slot 3 — not allowed
        ]);

        $response->assertStatus(422);
    }

    public function test_reply_speaker_is_required_for_reply_format(): void
    {
        [$debate, $leader, $members] = $this->makeTeamSelectDebate();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => [$members[0]->id, $members[1]->id, $members[2]->id],
        ]);

        $response->assertStatus(422);
    }

    public function test_resolve_stage_speaker_honors_reply_flag(): void
    {
        // Reply-format debate paused right before the reply stages (current_stage 6).
        $format = $this->replyFormat();
        $debate = Debate::factory()->create([
            'format_id'         => $format->id,
            'status'            => 'live',
            'current_stage'     => 6,
            'livekit_room_name' => 'debate-reply-main',
        ]);

        foreach ($format->deriveStages() as $stage) {
            DebatePhase::factory()->create([
                'debate_id'   => $debate->id,
                'name'        => $stage['name'],
                'order_index' => $stage['order_index'],
                'duration_seconds' => $stage['duration_seconds'],
                'status'      => $stage['order_index'] <= 6 ? 'completed' : 'pending',
                'is_reply'    => $stage['is_reply'],
            ]);
        }

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        // Build both sides; flag opp slot-2 as reply speaker.
        $oppReplyParticipant = null;
        foreach (['proposition', 'opposition'] as $side) {
            foreach ([1, 2, 3] as $order) {
                $u = User::factory()->create(['role' => 'debater', 'status' => 'active']);
                $p = DebateParticipant::factory()->create([
                    'debate_id' => $debate->id, 'user_id' => $u->id,
                    'role' => 'debater', 'side' => $side, 'status' => 'approved',
                    'speaking_phase_order' => $order,
                    'is_reply_speaker' => ($side === 'opposition' && $order === 2),
                ]);
                if ($side === 'opposition' && $order === 2) {
                    $oppReplyParticipant = $p;
                }
            }
        }

        // Advance from stage 6 → stage 7 (Opposition Reply, after the swap).
        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/next-stage")->assertStatus(200);

        $stage7 = DebatePhase::where('debate_id', $debate->id)->where('order_index', 7)->first();
        $this->assertEquals('Opposition Reply', $stage7->name);
        $this->assertEquals($oppReplyParticipant->id, $stage7->participant_id);
    }
}
