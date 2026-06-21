<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_team_pulls_current_members_and_excludes_past(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $member1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $member2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $pastOne = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $team = Team::factory()->create([
            'leader_id'  => $leader->id,
            'created_by' => $admin->id,
            'is_random'  => false,
        ]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id,  'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member1->id, 'priority' => 2, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member2->id, 'priority' => 3, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $pastOne->id, 'priority' => 4, 'status' => 'past']);

        // Assign only the leader, tagged with the team — the rest are auto-pulled.
        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $leader->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
            ],
        ]);

        $response->assertStatus(200);

        // Leader + both current members get approved proposition rows tagged with the team.
        foreach ([$leader->id, $member1->id, $member2->id] as $userId) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id,
                'user_id'   => $userId,
                'role'      => 'debater',
                'side'      => 'proposition',
                'status'    => 'approved',
                'team_id'   => $team->id,
            ]);
        }

        // The past member is NOT pulled in.
        $this->assertDatabaseMissing('debate_participants', [
            'debate_id' => $debate->id,
            'user_id'   => $pastOne->id,
        ]);

        // Exactly 3 rows created (leader + 2 current members), none for the past member.
        $this->assertEquals(3, DebateParticipant::where('debate_id', $debate->id)->count());
    }
}
