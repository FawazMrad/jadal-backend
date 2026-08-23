<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** top-10 leaderboards. */
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

    /**
     * The stats_visible exclusion is removed, so every
     * debater is ranked. (This test previously asserted an opted-out debater
     * was filtered OUT of the leaderboard.)
     */
    public function test_every_debater_is_ranked_no_opt_out_exclusion(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $top    = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 999]);

        $res = $this->actingAs($viewer)->getJson('/api/leaderboards/debaters?metric=points&limit=10');
        $ids = collect($res->json('data.entries'))->pluck('user_id')->all();

        $this->assertContains($top->id, $ids);
    }

    /** Filters are accepted on the debater leaderboard. */
    public function test_debater_leaderboard_accepts_date_and_framework_filters(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/debaters?metric=win_rate&from=2026-01&to=2026-07&frameworks=1')
            ->assertStatus(200);

        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/debaters?metric=avg_score&positions=P1')
            ->assertStatus(200);
    }

    /** positions and frameworks may never be combined. */
    public function test_leaderboard_rejects_positions_and_frameworks_together(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/debaters?metric=win_rate&positions=P1&frameworks=1')
            ->assertStatus(422);
    }

    /** A position is a per-debater slot — meaningless for a team aggregate. */
    public function test_team_leaderboard_rejects_positions(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/teams?metric=win_rate&positions=P1')
            ->assertStatus(422);

        // …but the filters that DO make sense for a team are accepted.
        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/teams?metric=win_rate&from=2026-01&frameworks=1')
            ->assertStatus(200);
    }

    /**
     * points is a cumulative Elo rating, not a per-debate value, so there is no
     * honest way to answer "points as of month X" — filtering it is rejected
     * rather than silently returning the all-time ranking.
     */
    public function test_points_metric_rejects_filters(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        foreach (['from=2026-01', 'to=2026-07', 'positions=P1', 'frameworks=1'] as $filter) {
            $this->actingAs($viewer)
                ->getJson("/api/leaderboards/debaters?metric=points&{$filter}")
                ->assertStatus(422);
        }

        // Unfiltered points still works.
        $this->actingAs($viewer)
            ->getJson('/api/leaderboards/debaters?metric=points')
            ->assertStatus(200);
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
