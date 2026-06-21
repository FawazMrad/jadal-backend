<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebateRegistrationVariantsTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledDebate(): Debate
    {
        return Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
    }

    /**
     * Non-random team: leader (debater) + one extra member, created_by a trainer (the coach).
     *
     * @return array{0: Team, 1: User, 2: User, 3: User} [team, leader, member, coach]
     */
    private function makeTeam(bool $random = false): array
    {
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $member = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $team = Team::factory()->create([
            'leader_id'  => $leader->id,
            'created_by' => $coach->id,
            'is_random'  => $random,
        ]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $leader->id, 'priority' => 1, 'status' => 'current']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'priority' => 2, 'status' => 'current']);

        return [$team, $leader, $member, $coach];
    }

    // ── Variant: debater ──────────────────────────────────────────────────────

    public function test_debater_registers_solo_with_null_side(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id,
            'user_id'   => $debater->id,
            'role'      => 'debater',
            'side'      => null,
            'status'    => 'pending',
            'team_id'   => null,
        ]);
    }

    public function test_legacy_role_field_still_works(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        // Old clients sent `role` instead of `as`.
        $response = $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['role' => 'debater']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $debater->id, 'role' => 'debater', 'status' => 'pending',
        ]);
    }

    // ── Variant: judge ────────────────────────────────────────────────────────

    public function test_judge_registers_solo_with_judge_side(): void
    {
        $debate = $this->scheduledDebate();
        $judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $response = $this->actingAs($judge)->postJson("/api/debates/{$debate->id}/register", ['as' => 'judge']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $judge->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'pending',
        ]);
    }

    // ── Variant: team ─────────────────────────────────────────────────────────

    public function test_team_leader_registers_whole_team(): void
    {
        $debate = $this->scheduledDebate();
        [$team, $leader, $member, $coach] = $this->makeTeam();

        $response = $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", [
            'as' => 'team', 'team_id' => $team->id,
        ]);

        $response->assertStatus(201);

        // Two debater rows (leader + member) and one trainer row (coach).
        foreach ([$leader->id, $member->id] as $debaterId) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $debaterId,
                'role' => 'debater', 'side' => null, 'status' => 'pending', 'team_id' => $team->id,
            ]);
        }
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $coach->id,
            'role' => 'trainer', 'side' => 'trainer', 'status' => 'pending', 'team_id' => $team->id,
        ]);
        $this->assertEquals(3, DebateParticipant::where('debate_id', $debate->id)->count());
    }

    public function test_team_coach_can_register_team(): void
    {
        $debate = $this->scheduledDebate();
        [$team, $leader, $member, $coach] = $this->makeTeam();

        $response = $this->actingAs($coach)->postJson("/api/debates/{$debate->id}/register", [
            'as' => 'team', 'team_id' => $team->id,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(3, DebateParticipant::where('debate_id', $debate->id)->count());
    }

    // ── Rejections ────────────────────────────────────────────────────────────

    public function test_role_mismatch_debater_variant_rejected(): void
    {
        $debate = $this->scheduledDebate();
        $judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($judge)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater'])
            ->assertStatus(403);
    }

    public function test_role_mismatch_judge_variant_rejected(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'judge'])
            ->assertStatus(403);
    }

    public function test_random_team_cannot_register(): void
    {
        $debate = $this->scheduledDebate();
        [$team, $leader] = $this->makeTeam(random: true);

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", [
            'as' => 'team', 'team_id' => $team->id,
        ])->assertStatus(422);
    }

    public function test_non_leader_non_coach_cannot_register_team(): void
    {
        $debate = $this->scheduledDebate();
        [$team] = $this->makeTeam();
        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($outsider)->postJson("/api/debates/{$debate->id}/register", [
            'as' => 'team', 'team_id' => $team->id,
        ])->assertStatus(403);
    }

    public function test_team_variant_requires_team_id(): void
    {
        $debate = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'team'])
            ->assertStatus(422);
    }

    public function test_invalid_as_value_rejected(): void
    {
        $debate = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'viewer'])
            ->assertStatus(422);
    }

    public function test_double_registration_rejected(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater'])->assertStatus(201);
        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater'])->assertStatus(409);
    }

    public function test_team_double_registration_by_leader_rejected(): void
    {
        $debate = $this->scheduledDebate();
        [$team, $leader] = $this->makeTeam();

        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", ['as' => 'team', 'team_id' => $team->id])->assertStatus(201);
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", ['as' => 'team', 'team_id' => $team->id])->assertStatus(409);
    }

    public function test_registration_closed_when_not_scheduled(): void
    {
        $debate  = Debate::factory()->create(['status' => 'live', 'scheduled_at' => now()->subHour()]);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater'])
            ->assertStatus(422);
    }

    // ── Integration: registration → admin assignment upgrades the pending rows ──

    public function test_admin_assignment_upgrades_solo_registered_row(): void
    {
        $debate  = $this->scheduledDebate();
        $admin   = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        // Self-register (pending, side null).
        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/register", ['as' => 'debater'])->assertStatus(201);

        // Admin assigns side via the EXISTING endpoint (keyed on debate_id+user_id).
        $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $debater->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved'],
            ],
        ])->assertStatus(200);

        // Same row upgraded — no duplicate.
        $this->assertEquals(1, DebateParticipant::where('debate_id', $debate->id)->where('user_id', $debater->id)->count());
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
        ]);
    }

    public function test_admin_assignment_upgrades_team_registered_rows(): void
    {
        $debate = $this->scheduledDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        [$team, $leader, $member, $coach] = $this->makeTeam();

        // Team registration (3 pending rows).
        $this->actingAs($leader)->postJson("/api/debates/{$debate->id}/register", ['as' => 'team', 'team_id' => $team->id])->assertStatus(201);

        // Admin assigns the leader + member to the proposition side.
        $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $leader->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
                ['user_id' => $member->id, 'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'team_id' => $team->id],
            ],
        ])->assertStatus(200);

        // Leader and member rows upgraded in place; coach row stays pending; no duplicates.
        $this->assertEquals(1, DebateParticipant::where('debate_id', $debate->id)->where('user_id', $leader->id)->count());
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $leader->id, 'side' => 'proposition', 'status' => 'approved',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $member->id, 'side' => 'proposition', 'status' => 'approved',
        ]);
        $this->assertDatabaseHas('debate_participants', [
            'debate_id' => $debate->id, 'user_id' => $coach->id, 'role' => 'trainer', 'status' => 'pending',
        ]);
    }
}
