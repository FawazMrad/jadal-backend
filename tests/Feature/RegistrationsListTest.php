<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /debates/{debate}/registrations (teams / judges / solo) */
class RegistrationsListTest extends TestCase
{
    use RefreshDatabase;

    private function participant(Debate $debate, User $user, string $role, ?int $teamId, ?string $side): void
    {
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $user->id, 'team_id' => $teamId,
            'role' => $role, 'side' => $side, 'status' => 'pending',
            'is_chair' => false, 'is_attended' => false,
        ]);
    }

    public function test_groups_registrants_into_teams_judges_solo(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);

        // Team registration: 2 debaters + trainer, all tagged with the team.
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $d1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $d2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team = Team::factory()->create([
            'leader_id' => $d1->id, 'created_by' => $coach->id,
            'is_random' => false, 'status' => 'active',
        ]);
        $this->participant($debate, $d1, 'debater', $team->id, null);
        $this->participant($debate, $d2, 'debater', $team->id, null);
        $this->participant($debate, $coach, 'trainer', $team->id, 'trainer');

        // Solo judge + solo debater.
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $this->participant($debate, $judge, 'judge', null, 'judge');
        $solo = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->participant($debate, $solo, 'debater', null, null);

        $viewer = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $res = $this->actingAs($viewer)->getJson("/api/debates/{$debate->id}/registrations");

        $res->assertStatus(200);
        $res->assertJsonCount(1, 'data.teams');
        $res->assertJsonPath('data.teams.0.team.id', $team->id);
        $res->assertJsonPath('data.teams.0.members_count', 2); // debaters only, trainer excluded
        $res->assertJsonCount(1, 'data.judges');
        $res->assertJsonPath('data.judges.0.user.id', $judge->id);
        $res->assertJsonCount(1, 'data.solo');
        $res->assertJsonPath('data.solo.0.user.id', $solo->id);
    }

    public function test_empty_when_no_registrations(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $viewer = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $res = $this->actingAs($viewer)->getJson("/api/debates/{$debate->id}/registrations");
        $res->assertStatus(200);
        $res->assertJsonCount(0, 'data.teams');
        $res->assertJsonCount(0, 'data.judges');
        $res->assertJsonCount(0, 'data.solo');
    }
}
