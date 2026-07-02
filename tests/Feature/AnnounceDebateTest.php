<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V12 §2 — admin announce: judges + 2 teams (real or random) → announced. */
class AnnounceDebateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function realTeam(int $members = 3): Team
    {
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team   = Team::factory()->create([
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

    public function test_admin_announces_with_two_real_teams_and_judges(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $teamA = $this->realTeam(3);
        $teamB = $this->realTeam(3);
        $j1 = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $j2 = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $res = $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$j1->id, $j2->id],
            'teams'  => [['team_id' => $teamA->id], ['team_id' => $teamB->id]],
        ]);

        $res->assertStatus(200);
        $debate->refresh();
        $this->assertEquals('announced', $debate->status);
        $this->assertEquals($teamA->id, $debate->proposition_team_id);
        $this->assertEquals($teamB->id, $debate->opposition_team_id);

        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $j1->id, 'role' => 'judge',
            'status' => 'approved', 'is_chair' => true, 'judge_order' => 1,
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $j2->id, 'role' => 'judge',
            'status' => 'approved', 'is_chair' => false, 'judge_order' => 2,
        ]);
        // Debaters pooled with their team, side still null (assigned later).
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $teamA->leader_id,
            'role' => 'debater', 'team_id' => $teamA->id, 'side' => null,
        ]);
    }

    public function test_random_team_is_materialised_from_solo_user_ids(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $teamA = $this->realTeam(3);
        $solos = User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id], ['user_ids' => $solos->pluck('id')->all()]],
        ])->assertStatus(200);

        $debate->refresh();
        $this->assertEquals('announced', $debate->status);

        $randomTeamId = $debate->opposition_team_id;
        $this->assertDatabaseHas('teams', ['id' => $randomTeamId, 'is_random' => true]);
        foreach ($solos as $s) {
            $this->assertDatabaseHas('team_members', ['team_id' => $randomTeamId, 'user_id' => $s->id, 'status' => 'current']);
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $s->id,
                'role' => 'debater', 'team_id' => $randomTeamId, 'side' => null,
            ]);
        }
    }

    public function test_live_state_shows_both_teams_neutrally_when_announced(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $teamA = $this->realTeam(3);
        $teamB = $this->realTeam(3);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id], ['team_id' => $teamB->id]],
        ])->assertStatus(200);

        $res = $this->actingAs($judge)->getJson("/api/debates/{$debate->id}/live-state");
        $res->assertStatus(200);
        $res->assertJsonPath('data.debate.status', 'announced');
        $res->assertJsonPath('data.proposition.team.id', $teamA->id);
        $res->assertJsonCount(3, 'data.proposition.members');
        $res->assertJsonCount(0, 'data.proposition.speakers');
        $res->assertJsonPath('data.opposition.team.id', $teamB->id);
        $res->assertJsonCount(3, 'data.opposition.members');
        $res->assertJsonCount(1, 'data.judges');
    }

    public function test_only_scheduled_debates_can_be_announced(): void
    {
        $debate = Debate::factory()->create(['status' => 'live']);
        $teamA = $this->realTeam(3);
        $teamB = $this->realTeam(3);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id], ['team_id' => $teamB->id]],
        ])->assertStatus(422);
    }

    public function test_team_with_too_few_members_is_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $small = $this->realTeam(2);
        $teamB = $this->realTeam(3);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $small->id], ['team_id' => $teamB->id]],
        ])->assertStatus(422);
    }

    public function test_unselected_registrants_are_rejected_on_announce(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $teamA  = $this->realTeam(3);
        $teamB  = $this->realTeam(3);

        // A third team registered but won't be picked.
        $unpickedTeam = $this->realTeam(3);
        foreach (TeamMember::where('team_id', $unpickedTeam->id)->pluck('user_id') as $uid) {
            DebateParticipant::create([
                'debate_id' => $debate->id, 'user_id' => $uid, 'team_id' => $unpickedTeam->id,
                'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
            ]);
        }

        // A solo debater registered but won't be picked either.
        $unpickedSolo = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $unpickedSolo->id, 'team_id' => null,
            'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);

        // A second judge registered but won't be listed in the announce call.
        $unpickedJudge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $unpickedJudge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);

        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id], ['team_id' => $teamB->id]],
        ])->assertStatus(200);

        foreach (TeamMember::where('team_id', $unpickedTeam->id)->pluck('user_id') as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid, 'status' => 'rejected',
            ]);
        }
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $unpickedSolo->id, 'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $unpickedJudge->id, 'status' => 'rejected',
        ]);

        // The chosen judge and teams are untouched (still approved/pending as expected).
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $judge->id, 'status' => 'approved',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $teamA->leader_id, 'status' => 'pending',
        ]);
    }

    public function test_random_team_name_is_sequential(): void
    {
        $debateOne = Debate::factory()->create(['status' => 'scheduled']);
        $debateTwo = Debate::factory()->create(['status' => 'scheduled']);
        $teamA = $this->realTeam(3);
        $teamC = $this->realTeam(3);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $solosOne = User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debateOne->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id], ['user_ids' => $solosOne->pluck('id')->all()]],
        ])->assertStatus(200);

        $solosTwo = User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debateTwo->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamC->id], ['user_ids' => $solosTwo->pluck('id')->all()]],
        ])->assertStatus(200);

        $firstRandomId  = $debateOne->fresh()->opposition_team_id;
        $secondRandomId = $debateTwo->fresh()->opposition_team_id;

        $this->assertDatabaseHas('teams', ['id' => $firstRandomId, 'name' => 'Random-00001']);
        $this->assertDatabaseHas('teams', ['id' => $secondRandomId, 'name' => 'Random-00002']);
    }

    public function test_team_entry_cannot_be_both_team_id_and_user_ids(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled']);
        $teamA = $this->realTeam(3);
        $solos = User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/announce", [
            'judges' => [$judge->id],
            'teams'  => [['team_id' => $teamA->id, 'user_ids' => $solos->pluck('id')->all()], ['team_id' => $teamA->id]],
        ])->assertStatus(422);
    }
}
