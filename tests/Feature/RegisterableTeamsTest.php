<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /debates/{debate}/registerable-teams */
class RegisterableTeamsTest extends TestCase
{
    use RefreshDatabase;

    private function teamWithMembers(User $leader, User $coach, int $members = 3): Team
    {
        $team = Team::factory()->create([
            'leader_id' => $leader->id, 'created_by' => $coach->id,
            'is_random' => false, 'status' => 'active',
        ]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id, 'priority' => 1, 'status' => 'current']);
        for ($i = 2; $i <= $members; $i++) {
            $u = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            TeamMember::create(['team_id' => $team->id, 'user_id' => $u->id, 'priority' => $i, 'status' => 'current']);
        }

        return $team;
    }

    public function test_leader_sees_their_eligible_team(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->teamWithMembers($leader, $coach, 3);

        $res = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertStatus(200);
        $res->assertJsonPath('data.debate_id', $debate->id);
        $res->assertJsonPath('data.already_registered_team_id', null);
        $res->assertJsonCount(1, 'data.teams');
        $res->assertJsonPath('data.teams.0.eligible', true);
        $res->assertJsonPath('data.teams.0.ineligible_reason', null);
        $res->assertJsonPath('data.teams.0.members_count', 3);
    }

    public function test_trainer_sees_multiple_owned_teams(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $l1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $l2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->teamWithMembers($l1, $coach, 3);
        $this->teamWithMembers($l2, $coach, 3);

        $res = $this->actingAs($coach)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertStatus(200);
        $res->assertJsonCount(2, 'data.teams');
    }

    public function test_already_registered_team_is_flagged(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team   = $this->teamWithMembers($leader, $coach, 3);

        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $leader->id, 'team_id' => $team->id,
            'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);

        $res = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertStatus(200);
        $res->assertJsonPath('data.already_registered_team_id', $team->id);
        $res->assertJsonPath('data.teams.0.eligible', false);
        $res->assertJsonPath('data.teams.0.ineligible_reason', 'already_registered');
    }

    public function test_too_few_members_is_flagged(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->teamWithMembers($leader, $coach, 2); // below SPEAKERS_PER_SIDE

        $res = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertJsonPath('data.teams.0.eligible', false);
        $res->assertJsonPath('data.teams.0.ineligible_reason', 'too_few_members');
    }

    public function test_registration_closed_when_not_scheduled(): void
    {
        $debate = Debate::factory()->create(['status' => 'announced']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->teamWithMembers($leader, $coach, 3);

        $res = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertJsonPath('data.teams.0.ineligible_reason', 'registration_closed');
    }

    public function test_random_and_inactive_teams_are_excluded(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        Team::factory()->create(['leader_id' => $leader->id, 'created_by' => $coach->id, 'is_random' => true, 'status' => 'active']);
        Team::factory()->create(['leader_id' => $leader->id, 'created_by' => $coach->id, 'is_random' => false, 'status' => 'inactive']);

        $res = $this->actingAs($leader)->getJson("/api/debates/{$debate->id}/registerable-teams");
        $res->assertStatus(200);
        $res->assertJsonCount(0, 'data.teams');
    }
}
