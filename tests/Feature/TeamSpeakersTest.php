<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSpeakersTest extends TestCase
{
    use RefreshDatabase;

    private function makeDebateWithTeam(string $side = 'proposition'): array
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
            'format_id'     => $format->id,
            'status'        => 'teams-selected',
            'current_stage' => 0,
        ]);

        $leader  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team    = Team::factory()->create(['leader_id' => $leader->id]);
        $members = User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);

        foreach ($members as $member) {
            DebateParticipant::factory()->create([
                'debate_id'            => $debate->id,
                'user_id'              => $member->id,
                'role'                 => 'debater',
                'side'                 => $side,
                'team_id'              => $team->id,
                'status'               => 'approved',
                'speaking_phase_order' => null,
            ]);
        }

        return [$debate, $leader, $team, $members];
    }

    public function test_team_leader_can_set_3_speakers(): void
    {
        [$debate, $leader, $team, $members] = $this->makeDebateWithTeam();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => $members->pluck('id')->toArray(),
        ]);

        $response->assertStatus(200);

        // Verify speaking orders are set.
        foreach ($members as $i => $member) {
            $p = DebateParticipant::where('debate_id', $debate->id)
                ->where('user_id', $member->id)
                ->first();
            $this->assertEquals($i + 1, $p->speaking_phase_order);
        }
    }

    public function test_non_leader_cannot_set_speakers(): void
    {
        [$debate, $leader, $team, $members] = $this->makeDebateWithTeam();
        $outsider = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $response = $this->actingAs($outsider)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => $members->pluck('id')->toArray(),
        ]);

        $response->assertStatus(403);
    }

    public function test_must_provide_exactly_3_speakers(): void
    {
        [$debate, $leader, $team, $members] = $this->makeDebateWithTeam();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => $members->take(2)->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
    }

    public function test_speaker_must_be_approved_on_correct_side(): void
    {
        [$debate, $leader, $team, $members] = $this->makeDebateWithTeam();
        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        // Replace the 3rd member's id with the outsider's id (not a participant on this side).
        $ids    = $members->take(2)->pluck('id')->toArray();
        $ids[]  = $outsider->id;

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => $ids,
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_set_speakers_if_stage_already_started(): void
    {
        [$debate, $leader, $team, $members] = $this->makeDebateWithTeam();
        $debate->update(['current_stage' => 1]);

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-speakers", [
            'side'             => 'proposition',
            'speaker_user_ids' => $members->pluck('id')->toArray(),
        ]);

        $response->assertStatus(422);
    }
}
