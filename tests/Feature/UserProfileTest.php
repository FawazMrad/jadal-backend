<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprinkles §6.1–§6.4 — public user profile, achievements, team history. */
class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_hides_email_phone_from_strangers_but_shows_to_self_and_admin(): void
    {
        $target = User::factory()->create([
            'role' => 'debater', 'status' => 'active',
            'birth_date' => now()->subYears(20)->toDateString(), 'location' => 'Damascus',
        ]);
        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $admin    = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $asStranger = $this->actingAs($stranger)->getJson("/api/users/{$target->id}");
        $asStranger->assertStatus(200);
        $asStranger->assertJsonPath('data.id', $target->id);
        $asStranger->assertJsonPath('data.age', 20);
        $asStranger->assertJsonPath('data.location', 'Damascus');
        $this->assertArrayNotHasKey('email', $asStranger->json('data'));
        $this->assertArrayNotHasKey('phone', $asStranger->json('data'));

        $this->actingAs($target)->getJson("/api/users/{$target->id}")
            ->assertJsonPath('data.email', $target->email);
        $this->actingAs($admin)->getJson("/api/users/{$target->id}")
            ->assertJsonPath('data.email', $target->email);
    }

    public function test_top_achievements_ride_inline_ordered_by_rank_then_recency(): void
    {
        $target = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        foreach ([
            ['rank' => 'participation', 'name' => 'P',  'awarded_at' => now()->subDays(1)],
            ['rank' => 'gold',          'name' => 'G1', 'awarded_at' => now()->subDays(10)],
            ['rank' => 'gold',          'name' => 'G2', 'awarded_at' => now()->subDays(2)],
            ['rank' => 'silver',        'name' => 'S',  'awarded_at' => now()],
            ['rank' => 'bronze',        'name' => 'B',  'awarded_at' => now()],
        ] as $a) {
            Achievement::create($a + ['user_id' => $target->id]);
        }

        $res = $this->actingAs($viewer)->getJson("/api/users/{$target->id}");
        $names = collect($res->json('data.top_achievements'))->pluck('name')->all();

        // Top 4: both golds (newest first), then silver, then bronze.
        $this->assertSame(['G2', 'G1', 'S', 'B'], $names);
    }

    public function test_achievements_endpoint_is_paginated(): void
    {
        $target = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        for ($i = 1; $i <= 7; $i++) {
            Achievement::create([
                'user_id' => $target->id, 'name' => "A{$i}",
                'rank' => 'participation', 'awarded_at' => now()->subDays($i),
            ]);
        }

        $res = $this->actingAs($target)->getJson("/api/users/{$target->id}/achievements?per_page=5");
        $res->assertStatus(200);
        $res->assertJsonCount(5, 'data');
    }

    public function test_admin_can_award_and_revoke_achievements(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $target = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $res = $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/achievements", [
            'name' => 'Best Speaker — Regional Finals 2026',
            'rank' => 'gold',
        ]);
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $this->assertDatabaseHas('achievements', ['id' => $id, 'user_id' => $target->id, 'rank' => 'gold']);

        // Non-admin cannot award.
        $this->actingAs($target)->postJson("/api/admin/users/{$target->id}/achievements", [
            'name' => 'x', 'rank' => 'gold',
        ])->assertStatus(403);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}/achievements/{$id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('achievements', ['id' => $id]);
    }

    public function test_teams_current_and_history(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $viewer  = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $currentTeam = Team::factory()->create(['leader_id' => $debater->id]);
        TeamMember::create(['team_id' => $currentTeam->id, 'user_id' => $debater->id, 'priority' => 1, 'status' => 'current']);

        $pastTeam = Team::factory()->create();
        TeamMember::create(['team_id' => $pastTeam->id, 'user_id' => $debater->id, 'priority' => 2, 'status' => 'past']);

        $current = $this->actingAs($viewer)->getJson("/api/users/{$debater->id}/teams");
        $current->assertStatus(200);
        $current->assertJsonCount(1, 'data');
        $current->assertJsonPath('data.0.team_id', $currentTeam->id);
        $current->assertJsonPath('data.0.role', 'leader');
        $current->assertJsonPath('data.0.left_at', null);

        $history = $this->actingAs($viewer)->getJson("/api/users/{$debater->id}/teams/history");
        $history->assertStatus(200);
        $history->assertJsonCount(1, 'data');
        $history->assertJsonPath('data.0.team_id', $pastTeam->id);
        $this->assertNotNull($history->json('data.0.left_at'));
    }

    public function test_coach_current_teams_listed_with_trainer_role(): void
    {
        $coach  = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        Team::factory()->create(['created_by' => $coach->id, 'is_random' => false, 'status' => 'active']);
        Team::factory()->create(['created_by' => $coach->id, 'is_random' => false, 'status' => 'inactive']);

        $current = $this->actingAs($viewer)->getJson("/api/users/{$coach->id}/teams");
        $current->assertJsonCount(1, 'data');
        $current->assertJsonPath('data.0.role', 'trainer');

        $history = $this->actingAs($viewer)->getJson("/api/users/{$coach->id}/teams/history");
        $history->assertJsonCount(1, 'data');
        $history->assertJsonPath('data.0.role', 'trainer');
    }

    public function test_profile_update_accepts_birth_date_and_location(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($user)->putJson('/api/profile', [
            'birth_date' => '2004-03-15',
            'location'   => 'Aleppo',
        ])->assertStatus(200);

        $user->refresh();
        $this->assertSame('2004-03-15', $user->birth_date->toDateString());
        $this->assertSame('Aleppo', $user->location);
    }
}
