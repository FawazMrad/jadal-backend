<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantsAssignmentRoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each entry is applied to its own (debate_id, user_id) row with exactly the
     * role/side it carries — even when several entries share a team_id and have
     * different roles. No entry overwrites another.
     */
    public function test_explicit_entries_keep_their_own_role_and_side(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        $debaterA = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debaterB = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $trainer  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $judge    = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        // All on the same team — previously this triggered cross-contamination.
        $team = Team::factory()->create([
            'leader_id'  => $debaterA->id,
            'created_by' => $trainer->id,
            'is_random'  => false,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $debaterA->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $debaterB->id, 'role' => 'debater', 'side' => 'opposition',  'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $trainer->id,  'role' => 'trainer', 'side' => 'trainer',     'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $judge->id,    'role' => 'judge',   'side' => 'judge',       'status' => 'approved', 'judge_order' => 1],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('debate_participants', ['debate_id' => $debate->id, 'user_id' => $debaterA->id, 'role' => 'debater', 'side' => 'proposition']);
        $this->assertDatabaseHas('debate_participants', ['debate_id' => $debate->id, 'user_id' => $debaterB->id, 'role' => 'debater', 'side' => 'opposition']);
        $this->assertDatabaseHas('debate_participants', ['debate_id' => $debate->id, 'user_id' => $trainer->id,  'role' => 'trainer', 'side' => 'trainer']);
        $this->assertDatabaseHas('debate_participants', ['debate_id' => $debate->id, 'user_id' => $judge->id,    'role' => 'judge',   'side' => 'judge']);

        // Exactly the four listed rows — nothing else.
        $this->assertEquals(4, DebateParticipant::where('debate_id', $debate->id)->count());
    }

    /**
     * A team member who is NOT listed in the payload must NOT be auto-pulled in,
     * even when another listed entry carries that member's team_id.
     */
    public function test_unlisted_team_members_are_not_pulled_in(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);

        $leader   = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $teammate = User::factory()->create(['role' => 'debater', 'status' => 'active']); // current member, NOT listed

        $team = Team::factory()->create(['leader_id' => $leader->id, 'created_by' => $admin->id, 'is_random' => false]);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id,   'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'priority' => 2, 'status' => 'current']);

        $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $leader->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
            ],
        ])->assertStatus(200);

        // Only the listed leader gets a row; the teammate is untouched.
        $this->assertDatabaseHas('debate_participants', ['debate_id' => $debate->id, 'user_id' => $leader->id]);
        $this->assertDatabaseMissing('debate_participants', ['debate_id' => $debate->id, 'user_id' => $teammate->id]);
        $this->assertEquals(1, DebateParticipant::where('debate_id', $debate->id)->count());
    }

    /**
     * An existing pending row (e.g. created by registration) is upgraded in place
     * when explicitly listed — same (debate_id, user_id) row, no duplicate.
     */
    public function test_listed_entry_upgrades_existing_row_in_place(): void
    {
        $admin   = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $existing = DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => null, 'status' => 'pending',
            'is_chair' => false, 'is_attended' => false,
        ]);

        $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $debater->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved'],
            ],
        ])->assertStatus(200);

        $existing->refresh();
        $this->assertEquals('proposition', $existing->side);
        $this->assertEquals('approved', $existing->status);
        $this->assertEquals(1, DebateParticipant::where('debate_id', $debate->id)->where('user_id', $debater->id)->count());
    }
}
