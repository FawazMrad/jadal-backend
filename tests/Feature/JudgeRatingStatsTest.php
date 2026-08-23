<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Feedbacks;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aggregate of the rating_judgement feedback a judge received. */
class JudgeRatingStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $judge;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $this->viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
    }

    /** A completed, revealed debate this judge judged, with the given ratings. */
    private function judgedDebate(array $ratings, string $date = '2026-03-01', ?User $judge = null): Debate
    {
        $judge ??= $this->judge;

        $debate = Debate::factory()->create([
            'status'              => 'completed',
            'scheduled_at'        => $date,
            'result_revealed_at'  => now(),
            'proposition_team_id' => Team::factory()->create()->id,
            'opposition_team_id'  => Team::factory()->create()->id,
        ]);

        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $judge->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        foreach ($ratings as $rating) {
            Feedbacks::create([
                'debate_id'    => $debate->id,
                'from_user_id' => User::factory()->create(['role' => 'debater', 'status' => 'active'])->id,
                'to_user_id'   => $judge->id,
                'type'         => 'rating_judgement',
                'content'      => null,
                'scores'       => ['rating' => $rating],
            ]);
        }

        return $debate;
    }

    public function test_aggregates_ratings_with_scale_and_distribution(): void
    {
        $this->judgedDebate([5, 4, 3]);

        $res = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200);

        $res->assertJsonPath('data.judge_id', $this->judge->id)
            ->assertJsonPath('data.rating_scale.min', 1)
            ->assertJsonPath('data.rating_scale.max', 5)
            ->assertJsonPath('data.totals.ratings_count', 3)
            ->assertJsonPath('data.totals.debates_rated', 1)
            ->assertJsonPath('data.totals.debates_judged', 1)
            ->assertJsonPath('data.totals.coverage', 1)
            ->assertJsonPath('data.totals.distribution.5', 1)
            ->assertJsonPath('data.totals.distribution.4', 1)
            ->assertJsonPath('data.totals.distribution.3', 1)
            ->assertJsonPath('data.totals.distribution.1', 0)
            ->assertJsonPath('data.reason', null);

        $this->assertEqualsWithDelta(4.0, $res->json('data.totals.avg_rating'), 1e-9);
    }

    public function test_rating_debate_rows_are_excluded(): void
    {
        $debate = $this->judgedDebate([4]);

        // Rates the DEBATE, not the judge — must not move the judge's average.
        Feedbacks::create([
            'debate_id'    => $debate->id,
            'from_user_id' => User::factory()->create(['role' => 'debater', 'status' => 'active'])->id,
            'to_user_id'   => null,
            'type'         => 'rating_debate',
            'content'      => null,
            'scores'       => ['rating' => 1],
        ]);

        $res = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200);

        $res->assertJsonPath('data.totals.ratings_count', 1);
        $this->assertEqualsWithDelta(4.0, $res->json('data.totals.avg_rating'), 1e-9);
    }

    public function test_coverage_is_rated_over_judged(): void
    {
        $this->judgedDebate([5]);
        $this->judgedDebate([]); // judged, nobody rated it

        $res = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200);

        $res->assertJsonPath('data.totals.debates_judged', 2)
            ->assertJsonPath('data.totals.debates_rated', 1)
            ->assertJsonPath('data.totals.coverage', 0.5);
    }

    public function test_peer_average_excludes_the_subject(): void
    {
        $other = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->judgedDebate([5, 5]);            // subject
        $this->judgedDebate([3], '2026-03-02', $other); // peer

        $res = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200);

        $this->assertEqualsWithDelta(5.0, $res->json('data.totals.avg_rating'), 1e-9);
        $this->assertEqualsWithDelta(3.0, $res->json('data.peer_average'), 1e-9);
    }

    public function test_peer_average_null_when_no_other_judge_rated(): void
    {
        $this->judgedDebate([4]);

        $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200)
            ->assertJsonPath('data.peer_average', null);
    }

    public function test_no_ratings_returns_200_with_a_reason(): void
    {
        $this->judgedDebate([]);

        $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200)
            ->assertJsonPath('data.totals.avg_rating', null)
            ->assertJsonPath('data.totals.ratings_count', 0)
            ->assertJsonPath('data.reason', 'no_ratings_received');
    }

    public function test_never_judged_is_distinguished_from_never_rated(): void
    {
        $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200)
            ->assertJsonPath('data.totals.coverage', null)
            ->assertJsonPath('data.reason', 'no_debates_judged');
    }

    public function test_month_grouping_zero_fills_quiet_months(): void
    {
        $this->judgedDebate([5], '2026-01-10');
        $this->judgedDebate([3], '2026-03-10');

        $res = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings?group_by=month&from=2026-01&to=2026-03")
            ->assertStatus(200);

        // February must be present and flat, not skipped.
        $res->assertJsonCount(3, 'data.buckets')
            ->assertJsonPath('data.buckets.1.label', '2026-02')
            ->assertJsonPath('data.buckets.1.ratings_count', 0)
            ->assertJsonPath('data.buckets.1.avg_rating', null);
    }

    public function test_non_judge_subject_is_422(): void
    {
        $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->viewer->id}/stats/ratings")
            ->assertStatus(422);
    }

    public function test_payload_leaks_no_rater_identity(): void
    {
        $this->judgedDebate([5]);

        $body = $this->actingAs($this->viewer)
            ->getJson("/api/judges/{$this->judge->id}/stats/ratings")
            ->assertStatus(200)
            ->getContent();

        foreach (['from_user_id', 'content', 'rater'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }
}
