<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSelfRosterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a team with a coach, a leader, and N pending debater rows on a debate.
     *
     * @return array{0: Team, 1: User, 2: User, 3: \Illuminate\Support\Collection<int,User>}
     *         [team, leader, coach, debaters]
     */
    private function makeTeamWithPendingPool(Debate $debate, int $debaters = 5): array
    {
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $people = User::factory()->count($debaters)->create(['role' => 'debater', 'status' => 'active']);
        $leader = $people->first();

        $team = Team::factory()->create([
            'leader_id'  => $leader->id,
            'created_by' => $coach->id,
            'is_random'  => false,
            'status'     => 'active',
        ]);

        foreach ($people as $debater) {
            DebateParticipant::create([
                'debate_id' => $debate->id, 'user_id' => $debater->id, 'team_id' => $team->id,
                'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
            ]);
        }
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $coach->id, 'team_id' => $team->id,
            'role' => 'trainer', 'side' => 'trainer', 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);

        return [$team, $leader, $coach, $people];
    }

    public function test_leader_selects_three_auto_approves_and_rejects_rest(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 5);
        $debate->update(['proposition_team_id' => $team->id]);

        $chosen = [$people[0]->id, $people[1]->id, $people[2]->id];

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => $chosen,
        ]);

        $response->assertStatus(200);

        // Chosen 3 → approved + proposition.
        foreach ($chosen as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid, 'role' => 'debater',
                'status' => 'approved', 'side' => 'proposition',
            ]);
        }
        // The other 2 → rejected.
        foreach ([$people[3]->id, $people[4]->id] as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid, 'role' => 'debater', 'status' => 'rejected',
            ]);
        }
        // Trainer → approved, side trainer.
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $coach->id, 'role' => 'trainer',
            'status' => 'approved', 'side' => 'trainer',
        ]);
    }

    public function test_coach_can_also_select(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['opposition_team_id' => $team->id]);

        $response = $this->actingAs($coach)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => $people->pluck('id')->all(),
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $people[0]->id, 'status' => 'approved', 'side' => 'opposition',
        ]);
    }

    public function test_non_leader_non_coach_is_forbidden(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['proposition_team_id' => $team->id]);

        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($outsider)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => $people->pluck('id')->all(),
        ])->assertStatus(403);
    }

    public function test_team_not_linked_to_a_side_is_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        // No side declared on the debate.

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => $people->pluck('id')->all(),
        ])->assertStatus(422);
    }

    public function test_wrong_speaker_count_is_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['proposition_team_id' => $team->id]);

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => [$people[0]->id, $people[1]->id], // only 2
        ])->assertStatus(422);
    }

    public function test_speaker_outside_team_pool_is_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['proposition_team_id' => $team->id]);

        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id,
            'speaker_user_ids' => [$people[0]->id, $people[1]->id, $stranger->id], // stranger not in pool
        ])->assertStatus(422);
    }

    public function test_only_scheduled_debate_allows_roster(): void
    {
        $debate = Debate::factory()->create(['status' => 'live', 'scheduled_at' => now()->subHour()]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['proposition_team_id' => $team->id]);

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => $people->pluck('id')->all(),
        ])->assertStatus(422);
    }

    public function test_replay_replaces_previous_selection(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        [$team, $leader, $coach, $people] = $this->makeTeamWithPendingPool($debate, 5);
        $debate->update(['proposition_team_id' => $team->id]);

        // First selection.
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => [$people[0]->id, $people[1]->id, $people[2]->id],
        ])->assertStatus(200);

        // Re-run with a different trio (swap p2 out for the previously-rejected p3).
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $team->id, 'speaker_user_ids' => [$people[0]->id, $people[1]->id, $people[3]->id],
        ])->assertStatus(200);

        // New trio approved.
        foreach ([$people[0]->id, $people[1]->id, $people[3]->id] as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid, 'status' => 'approved', 'side' => 'proposition',
            ]);
        }
        // Dropped p2 (was approved) now rejected; p4 still rejected.
        foreach ([$people[2]->id, $people[4]->id] as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid, 'status' => 'rejected',
            ]);
        }
    }

    public function test_debate_bumps_to_announced_when_both_sides_done_and_judge_approved(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);

        [$propTeam, $propLeader, , $propPeople] = $this->makeTeamWithPendingPool($debate, 3);
        [$oppTeam,  $oppLeader,  , $oppPeople]  = $this->makeTeamWithPendingPool($debate, 3);
        $debate->update(['proposition_team_id' => $propTeam->id, 'opposition_team_id' => $oppTeam->id]);

        // An approved judge already exists.
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $judge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);

        // Proposition completes — not enough yet (opp side empty).
        $this->actingAs($propLeader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $propTeam->id, 'speaker_user_ids' => $propPeople->pluck('id')->all(),
        ])->assertStatus(200);
        $this->assertEquals('scheduled', $debate->fresh()->status);

        // Opposition completes — now both sides + judge → announced.
        $this->actingAs($oppLeader)->postJson("/api/debates/{$debate->id}/team-roster", [
            'team_id' => $oppTeam->id, 'speaker_user_ids' => $oppPeople->pluck('id')->all(),
        ])->assertStatus(200);
        $this->assertEquals('announced', $debate->fresh()->status);
    }
}
