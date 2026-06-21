<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkDebateTeamsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function activeTeam(): Team
    {
        return Team::factory()->create(['is_random' => false, 'status' => 'active']);
    }

    public function test_admin_links_both_sides(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $prop = $this->activeTeam();
        $opp  = $this->activeTeam();

        $response = $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/teams", [
            'proposition_team_id' => $prop->id,
            'opposition_team_id'  => $opp->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('debates', [
            'id' => $debate->id, 'proposition_team_id' => $prop->id, 'opposition_team_id' => $opp->id,
        ]);
    }

    public function test_same_team_on_both_sides_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $team = $this->activeTeam();

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/teams", [
            'proposition_team_id' => $team->id,
            'opposition_team_id'  => $team->id,
        ])->assertStatus(422);
    }

    public function test_inactive_team_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $prop = $this->activeTeam();
        $opp  = Team::factory()->create(['is_random' => false, 'status' => 'inactive']);

        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/teams", [
            'proposition_team_id' => $prop->id,
            'opposition_team_id'  => $opp->id,
        ])->assertStatus(422);
    }

    public function test_team_already_linked_rejected(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $prop = $this->activeTeam();
        $opp  = $this->activeTeam();
        $debate->update(['proposition_team_id' => $prop->id, 'opposition_team_id' => $opp->id]);

        // Re-using an already-linked team is rejected.
        $other = $this->activeTeam();
        $this->actingAs($this->admin())->postJson("/api/admin/debates/{$debate->id}/teams", [
            'proposition_team_id' => $prop->id,
            'opposition_team_id'  => $other->id,
        ])->assertStatus(422);
    }

    public function test_requires_admin(): void
    {
        $debate = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(3)]);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $prop = $this->activeTeam();
        $opp  = $this->activeTeam();

        $this->actingAs($trainer)->postJson("/api/admin/debates/{$debate->id}/teams", [
            'proposition_team_id' => $prop->id,
            'opposition_team_id'  => $opp->id,
        ])->assertStatus(403);
    }
}
