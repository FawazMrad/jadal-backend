<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V2 §3 — top-10 leaderboards. */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_debaters_points_leaderboard_is_ranked_descending(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 0]);
        $high = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 300]);
        $low  = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 50]);

        $res = $this->actingAs($viewer)->getJson('/api/leaderboards/debaters?metric=points&limit=10');
        $res->assertStatus(200);
        $res->assertJsonPath('data.metric', 'points');

        $ids = collect($res->json('data.entries'))->pluck('user_id')->all();
        $this->assertEquals(array_search($high->id, $ids), 0);
        $this->assertLessThan(array_search($low->id, $ids), array_search($high->id, $ids));
    }

    public function test_opted_out_debater_excluded_from_points_leaderboard(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $hidden = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 999, 'stats_visible' => false]);

        $res = $this->actingAs($viewer)->getJson('/api/leaderboards/debaters?metric=points&limit=10');
        $ids = collect($res->json('data.entries'))->pluck('user_id')->all();

        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_teams_points_leaderboard_excludes_random_teams(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $real   = Team::factory()->create(['is_random' => false, 'points' => 100]);
        $random = Team::factory()->create(['is_random' => true, 'points' => 500]);

        $res = $this->actingAs($viewer)->getJson('/api/leaderboards/teams?metric=points&limit=10');
        $res->assertStatus(200);
        $ids = collect($res->json('data.entries'))->pluck('team_id')->all();

        $this->assertContains($real->id, $ids);
        $this->assertNotContains($random->id, $ids);
    }

    public function test_best_speaker_metric_rejected_for_teams(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($viewer)->getJson('/api/leaderboards/teams?metric=best_speaker')->assertStatus(422);
    }

    public function test_invalid_metric_rejected(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($viewer)->getJson('/api/leaderboards/debaters?metric=bogus')->assertStatus(422);
    }

    public function test_limit_is_respected(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        User::factory()->count(5)->create(['role' => 'debater', 'status' => 'active']);

        $res = $this->actingAs($viewer)->getJson('/api/leaderboards/debaters?metric=points&limit=2');
        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data.entries'));
    }
}
