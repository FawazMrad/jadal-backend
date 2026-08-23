<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * per-team analytics endpoints.
 *
 * The bucketed metrics must stay envelope-compatible with the debater API (the
 * client reuses one parser), and the line-up analysis must group speakers
 * order-insensitively — those are the two things most likely to silently drift.
 */
class TeamStatsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->team  = Team::factory()->create([
            'created_by' => $this->coach->id,
            'is_random'  => false,
            'status'     => 'active',
        ]);
    }

    /**
     * One completed debate for the team.
     *
     * @param  array<int, array{0:User, 1:int, 2:float}>  $speakers  [user, stage_order, score]
     */
    private function debateWith(array $speakers, string $winningSide = 'proposition', string $date = '2026-03-01'): Debate
    {
        $debate = Debate::factory()->create([
            'status'              => 'completed',
            'scheduled_at'        => $date,
            'result_revealed_at'  => now(),
            'proposition_team_id' => $this->team->id,
            'opposition_team_id'  => Team::factory()->create()->id,
        ]);

        $stages = [];
        foreach ($speakers as [$user, $order, $score]) {
            DebateParticipant::firstOrCreate(
                ['debate_id' => $debate->id, 'user_id' => $user->id],
                [
                    'team_id' => $this->team->id, 'role' => 'debater', 'side' => 'proposition',
                    'status'  => 'approved', 'is_chair' => false, 'is_attended' => false,
                ]
            );
            $stages[] = ['stage_order' => $order, 'user_id' => $user->id, 'score' => $score];
        }

        DebateResult::create([
            'debate_id'    => $debate->id,
            'judge_id'     => User::factory()->create(['role' => 'judge', 'status' => 'active'])->id,
            'winning_side' => $winningSide,
            'scores'       => ['stages' => $stages, 'notes' => null],
            'submitted_at' => now(),
        ]);

        return $debate;
    }

    private function debater(): User
    {
        return User::factory()->create(['role' => 'debater', 'status' => 'active']);
    }

    // ── ───────────────────────────────────────────────────────────────────

    public function test_win_rate_returns_the_bucketed_debater_api_envelope(): void
    {
        $a = $this->debater();
        $this->debateWith([[$a, 1, 80.0]], 'proposition');
        $this->debateWith([[$a, 1, 60.0]], 'opposition');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/win-rate")
            ->assertStatus(200);

        $res->assertJsonPath('data.stat', 'win_rate')
            ->assertJsonPath('data.grouping', 'none')
            ->assertJsonPath('data.series_dimension', 'none')
            ->assertJsonPath('data.total_n_debates', 2)
            ->assertJsonPath('data.buckets.0.label', 'all')
            ->assertJsonPath('data.buckets.0.series.0.key', 'all')
            ->assertJsonPath('data.buckets.0.series.0.value', 0.5)
            ->assertJsonPath('data.buckets.0.series.0.n_debates', 2);
    }

    public function test_avg_score_buckets_by_month(): void
    {
        $a = $this->debater();
        $this->debateWith([[$a, 1, 70.0]], 'proposition', '2026-01-10');
        $this->debateWith([[$a, 1, 90.0]], 'proposition', '2026-02-10');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/avg-score?group_by=month&from=2026-01&to=2026-02")
            ->assertStatus(200);

        // Whole-number values serialize without their zero fraction (70.0 -> 70),
        // so these compare numerically rather than with assertJsonPath's ===.
        $res->assertJsonPath('data.grouping', 'by_month')
            ->assertJsonPath('data.buckets.0.label', '2026-01')
            ->assertJsonPath('data.buckets.1.label', '2026-02');

        $this->assertEqualsWithDelta(70.0, $res->json('data.buckets.0.series.0.value'), 1e-9);
        $this->assertEqualsWithDelta(90.0, $res->json('data.buckets.1.series.0.value'), 1e-9);
    }

    public function test_improvement_uses_the_debater_apis_component_keys_and_reason(): void
    {
        $a = $this->debater();
        $this->debateWith([[$a, 1, 70.0]], 'proposition', '2026-01-10');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/improvement")
            ->assertStatus(200);

        // One bucket is below min_buckets_for_index, so this is the guarded path.
        $res->assertJsonPath('data.stat', 'improvement')
            ->assertJsonPath('data.index', null)
            ->assertJsonPath('data.reason', 'insufficient_history')
            ->assertJsonPath('data.granularity', 'monthly');
    }

    public function test_activity_reports_members_counted(): void
    {
        $member = $this->debater();
        TeamMember::create([
            'team_id' => $this->team->id, 'user_id' => $member->id,
            'status'  => 'current', 'priority' => 1,
        ]);

        $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/activity")
            ->assertStatus(200)
            ->assertJsonPath('data.stat', 'activity')
            ->assertJsonPath('data.members_counted', 1);
    }

    // ── — combinations ──────────────────────────────────────────────────────

    public function test_combination_key_is_order_insensitive(): void
    {
        [$a, $b, $c] = [$this->debater(), $this->debater(), $this->debater()];

        // Same three people, different slots each time.
        $this->debateWith([[$a, 1, 80.0], [$b, 3, 80.0], [$c, 5, 80.0]], 'proposition');
        $this->debateWith([[$b, 1, 70.0], [$c, 3, 70.0], [$a, 5, 70.0]], 'proposition');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations")
            ->assertStatus(200);

        // One line-up, not two.
        $res->assertJsonPath('data.distinct_combinations', 1)
            ->assertJsonCount(1, 'data.combinations')
            ->assertJsonPath('data.combinations.0.n_debates', 2)
            ->assertJsonPath('data.combinations.0.size', 3)
            ->assertJsonPath('data.combination_size', 3);

        $this->assertEqualsWithDelta(1.0, $res->json('data.combinations.0.win_rate'), 1e-9);
        $this->assertEqualsWithDelta(75.0, $res->json('data.combinations.0.avg_score'), 1e-9);
    }

    public function test_min_debates_filters_but_distinct_count_is_pre_filter(): void
    {
        [$a, $b, $c, $d] = [$this->debater(), $this->debater(), $this->debater(), $this->debater()];

        // Line-up 1: twice. Line-up 2: once (below the default min_debates=2).
        $this->debateWith([[$a, 1, 80.0], [$b, 3, 80.0]], 'proposition');
        $this->debateWith([[$a, 1, 80.0], [$b, 3, 80.0]], 'proposition');
        $this->debateWith([[$c, 1, 90.0], [$d, 3, 90.0]], 'proposition');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations")
            ->assertStatus(200);

        $res->assertJsonPath('data.distinct_combinations', 2)
            ->assertJsonCount(1, 'data.combinations')
            ->assertJsonPath('data.min_debates', 2)
            ->assertJsonPath('data.total_debates_considered', 3);
    }

    public function test_departed_members_stay_in_history_but_are_flagged(): void
    {
        [$a, $b] = [$this->debater(), $this->debater()];
        TeamMember::create(['team_id' => $this->team->id, 'user_id' => $a->id, 'status' => 'current', 'priority' => 1]);
        TeamMember::create(['team_id' => $this->team->id, 'user_id' => $b->id, 'status' => 'past', 'priority' => 2]);

        $this->debateWith([[$a, 1, 80.0], [$b, 3, 80.0]], 'proposition');
        $this->debateWith([[$a, 1, 80.0], [$b, 3, 80.0]], 'proposition');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations")
            ->assertStatus(200);

        $members = collect($res->json('data.combinations.0.members'))->keyBy('user_id');
        $this->assertTrue($members[$a->id]['is_current_member']);
        $this->assertFalse($members[$b->id]['is_current_member']);
    }

    public function test_empty_window_is_200_with_a_reason_not_an_error(): void
    {
        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations?from=2020-01&to=2020-12")
            ->assertStatus(200);

        $res->assertJsonPath('data.combinations', [])
            ->assertJsonPath('data.reason', 'no_debates_in_range')
            ->assertJsonPath('data.team_baseline.n_debates', 0);
    }

    public function test_baseline_accompanies_the_combinations(): void
    {
        [$a, $b] = [$this->debater(), $this->debater()];
        $this->debateWith([[$a, 1, 90.0], [$b, 3, 90.0]], 'proposition');
        $this->debateWith([[$a, 1, 50.0], [$b, 3, 50.0]], 'opposition');

        $res = $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations?min_debates=1")
            ->assertStatus(200);

        $res->assertJsonPath('data.team_baseline.n_debates', 2)
            ->assertJsonPath('data.team_baseline.win_rate', 0.5);

        $this->assertEqualsWithDelta(70.0, $res->json('data.team_baseline.avg_score'), 1e-9);
    }

    // ── Permissions ────────────────────────────────────────────────────────────

    public function test_another_coach_gets_403(): void
    {
        $other = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $this->actingAs($other)
            ->getJson("/api/teams/{$this->team->id}/stats/combinations")
            ->assertStatus(403);
    }

    public function test_admin_may_read_any_teams_stats(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->getJson("/api/teams/{$this->team->id}/stats/win-rate")
            ->assertStatus(200);
    }

    public function test_random_teams_are_rejected(): void
    {
        $random = Team::factory()->create(['created_by' => $this->coach->id, 'is_random' => true]);

        $this->actingAs($this->coach)
            ->getJson("/api/teams/{$random->id}/stats/combinations")
            ->assertStatus(422);
    }

    public function test_positions_and_frameworks_together_are_422(): void
    {
        $this->actingAs($this->coach)
            ->getJson("/api/teams/{$this->team->id}/stats/win-rate?positions=P1&frameworks=1")
            ->assertStatus(422);
    }
}
