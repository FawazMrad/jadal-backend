<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingParticipantsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_pending_rows_for_that_debate_and_team(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);

        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $member = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $team = Team::factory()->create(['leader_id' => $leader->id, 'created_by' => $coach->id, 'is_random' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id, 'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'priority' => 2, 'status' => 'current']);

        // Registration creates the pending, role-tagged rows (leader+member debaters, coach trainer).
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", [
            'as' => 'team', 'team_id' => $team->id,
        ])->assertStatus(201);

        // Noise that must be excluded: a pending row on another team, same debate.
        $otherTeam = Team::factory()->create(['is_random' => false]);
        $otherUser = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $otherUser->id, 'team_id' => $otherTeam->id,
            'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/debates/{$debate->id}/teams/{$team->id}/pending-participants");

        $response->assertStatus(200);

        $userIds = collect($response->json('data'))->pluck('user.id')->all();
        sort($userIds);
        $expected = [$leader->id, $member->id, $coach->id];
        sort($expected);

        $this->assertEquals($expected, $userIds);

        // Role tagging from registration is preserved.
        $byUser = collect($response->json('data'))->keyBy('user.id');
        $this->assertEquals('debater', $byUser[$leader->id]['role']);
        $this->assertEquals('debater', $byUser[$member->id]['role']);
        $this->assertEquals('trainer', $byUser[$coach->id]['role']);

        // Every returned row is pending and tagged with this team.
        foreach ($response->json('data') as $row) {
            $this->assertEquals('pending', $row['status']);
            $this->assertEquals($team->id, $row['team_id']);
        }
    }

    public function test_approved_rows_are_excluded(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $team   = Team::factory()->create(['is_random' => false]);

        $pendingUser  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $approvedUser = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $pendingUser->id, 'team_id' => $team->id,
            'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false, 'is_attended' => false,
        ]);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $approvedUser->id, 'team_id' => $team->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/debates/{$debate->id}/teams/{$team->id}/pending-participants");

        $response->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('user.id')->all();
        $this->assertEquals([$pendingUser->id], $userIds);
    }

    public function test_requires_admin(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $team   = Team::factory()->create(['is_random' => false]);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)
            ->getJson("/api/admin/debates/{$debate->id}/teams/{$team->id}/pending-participants")
            ->assertStatus(403);
    }
}
