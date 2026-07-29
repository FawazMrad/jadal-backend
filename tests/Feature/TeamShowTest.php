<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /teams/{team} — single-team detail.
 *
 * This route used to live inside the role:admin,trainer group and was gated on
 * created_by only, so a debater viewing their own team was rejected by the
 * role middleware before the controller even ran. Read access is now the
 * trainer who created it, the leader, or a current member — while every WRITE
 * endpoint stays restricted to created_by.
 */
class TeamShowTest extends TestCase
{
    use RefreshDatabase;

    private function team(User $trainer, User $leader): Team
    {
        $team = Team::factory()->create([
            'created_by' => $trainer->id,
            'leader_id'  => $leader->id,
            'status'     => 'active',
            'is_random'  => false,
        ]);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $leader->id,
            'priority' => 1, 'status' => 'current',
        ]);

        return $team;
    }

    public function test_creating_trainer_can_view_and_payload_matches_the_list_item_shape(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        $res = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}");

        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('data.id', $team->id);
        $res->assertJsonStructure([
            'success', 'message',
            'data' => [
                'id', 'name', 'status', 'is_random', 'leader', 'created_by',
                'members_count', 'members', 'current_members',
                'created_at', 'updated_at',
            ],
        ]);
    }

    public function test_team_leader_can_view(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        $this->actingAs($leader)->getJson("/api/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $team->id);
    }

    /** The core fix: a plain debater member was previously blocked by role middleware. */
    public function test_current_member_debater_can_view(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $member  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $member->id,
            'priority' => 2, 'status' => 'current',
        ]);

        $this->actingAs($member)->getJson("/api/teams/{$team->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $team->id);
    }

    public function test_past_member_is_forbidden(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $former  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        TeamMember::create([
            'team_id' => $team->id, 'user_id' => $former->id,
            'priority' => 3, 'status' => 'past',
        ]);

        $this->actingAs($former)->getJson("/api/teams/{$team->id}")->assertStatus(403);
    }

    public function test_unrelated_users_are_forbidden(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        foreach (['debater', 'trainer', 'judge'] as $role) {
            $outsider = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($outsider)->getJson("/api/teams/{$team->id}")
                ->assertStatus(403);
        }
    }

    public function test_missing_team_is_404_even_for_a_member_of_another_team(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->team($trainer, $leader);

        $this->actingAs($leader)->getJson('/api/teams/999999')->assertStatus(404);
    }

    /** Widening READ access must not have widened write access. */
    public function test_member_still_cannot_write_to_the_team(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $leader  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = $this->team($trainer, $leader);

        // Debaters are not in the write group at all -> role middleware rejects.
        $this->actingAs($leader)->putJson("/api/teams/{$team->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);
        $this->actingAs($leader)->deleteJson("/api/teams/{$team->id}")
            ->assertStatus(403);

        // A DIFFERENT trainer is in the write group but does not own it -> 403.
        $otherTrainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->actingAs($otherTrainer)->putJson("/api/teams/{$team->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame($team->name, $team->fresh()->name);
    }

    /** /teams/options is a literal path and must not be captured by {team}. */
    public function test_options_route_is_not_shadowed_by_the_team_placeholder(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($user)->getJson('/api/teams/options')
            ->assertStatus(200)
            ->assertJsonPath('message', 'تم جلب خيارات الفرق. | Team options retrieved.');
    }
}
