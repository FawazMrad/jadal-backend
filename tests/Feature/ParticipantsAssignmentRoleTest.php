<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantsAssignmentRoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduces the role/side corruption bug: a trainer entry carrying a
     * team_id auto-pulls the team's current members and overwrites the
     * debater rows that were explicitly listed in the SAME request, turning
     * them into trainer/trainer.
     */
    public function test_explicit_entries_keep_their_own_role_and_side(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        $debaterA = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debaterB = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $trainer  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $judge    = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        // debaterA, debaterB and the trainer are all CURRENT members of one team.
        $team = Team::factory()->create([
            'leader_id'  => $debaterA->id,
            'created_by' => $trainer->id,
            'is_random'  => false,
        ]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $debaterA->id, 'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $debaterB->id, 'priority' => 2, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $trainer->id,  'priority' => 3, 'status' => 'current']);

        // Matches the bug report ordering: debaters first, trainer + judge last.
        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $debaterA->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $debaterB->id, 'role' => 'debater', 'side' => 'opposition',  'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $trainer->id,  'role' => 'trainer', 'side' => 'trainer',     'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $judge->id,    'role' => 'judge',   'side' => 'judge',       'status' => 'approved', 'judge_order' => 1],
            ],
        ]);

        $response->assertStatus(200);

        // Each explicit entry must keep EXACTLY its own role/side — no contamination.
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $debaterA->id, 'role' => 'debater', 'side' => 'proposition',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $debaterB->id, 'role' => 'debater', 'side' => 'opposition',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $trainer->id, 'role' => 'trainer', 'side' => 'trainer',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $judge->id, 'role' => 'judge', 'side' => 'judge',
        ]);
    }

    /**
     * The team auto-pull convenience must still work for members who are NOT
     * explicitly listed in the request.
     */
    public function test_auto_pull_still_applies_to_non_listed_members(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        $leader   = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $teammate = User::factory()->create(['role' => 'debater', 'status' => 'active']); // NOT listed explicitly

        $team = Team::factory()->create(['leader_id' => $leader->id, 'created_by' => $admin->id, 'is_random' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id,   'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'priority' => 2, 'status' => 'current']);

        $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $leader->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
            ],
        ])->assertStatus(200);

        // The non-listed teammate is auto-pulled onto the same side.
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $teammate->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id,
        ]);
    }
}
