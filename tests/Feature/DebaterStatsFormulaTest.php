<?php

namespace Tests\Feature;

use App\Models\MotionFramework;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCompletedDebates;
use Tests\TestCase;

class DebaterStatsFormulaTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedDebates;

    // ── Stat 1: win rate ───────────────────────────────────────────────────────

    public function test_win_rate_grouping_none(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'side' => 'proposition', 'winning_side' => 'proposition']); // win
        $this->completedDebate($d, ['date' => '2025-03-05', 'side' => 'opposition',  'winning_side' => 'opposition']);  // win
        $this->completedDebate($d, ['date' => '2025-03-09', 'side' => 'proposition', 'winning_side' => 'opposition']);  // loss

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate");

        $r->assertStatus(200);
        $r->assertJsonPath('data.stat', 'win_rate');
        $r->assertJsonPath('data.total_n_debates', 3);
        $this->assertEqualsWithDelta(0.6667, $r->json('data.buckets.0.series.0.value'), 0.0001);
        $this->assertEquals(3, $r->json('data.buckets.0.series.0.n_debates'));
        $this->assertEquals('all', $r->json('data.buckets.0.label'));
    }

    // ── Stat 2: average speech score ───────────────────────────────────────────

    public function test_avg_score_is_mean_of_normalized_per_debate(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'main' => 80]);
        $this->completedDebate($d, ['date' => '2025-03-05', 'main' => 90]);
        $this->completedDebate($d, ['date' => '2025-03-09', 'main' => 70]);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/avg-score");

        $r->assertStatus(200);
        $this->assertEqualsWithDelta(80.0, $r->json('data.buckets.0.series.0.value'), 0.0001);
        $this->assertEquals(3, $r->json('data.buckets.0.series.0.n_debates'));
    }

    public function test_avg_score_no_reply_multiplier(): void
    {
        // A debate with a main (80) and a reply (40). With no ×2, mean = 60.
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'side' => 'proposition', 'main' => 80, 'reply' => 40]);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/avg-score");

        $this->assertEqualsWithDelta(60.0, $r->json('data.buckets.0.series.0.value'), 0.0001);
    }

    // ── Stat 2.5: best speaker ─────────────────────────────────────────────────

    public function test_best_speaker_count_and_rate_with_tie(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'main' => 80, 'other_main' => 70]); // best
        $this->completedDebate($d, ['date' => '2025-03-05', 'main' => 60, 'other_main' => 90]); // not
        $this->completedDebate($d, ['date' => '2025-03-09', 'main' => 75, 'other_main' => 75]); // tie -> best

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/best-speaker");

        $r->assertStatus(200);
        $this->assertEquals(2, $r->json('data.buckets.0.series.0.count'));
        $this->assertEqualsWithDelta(0.6667, $r->json('data.buckets.0.series.0.rate'), 0.0001);
        $this->assertEquals(3, $r->json('data.buckets.0.series.0.n_debates'));
    }

    // ── Stat 3: score ranking ──────────────────────────────────────────────────

    public function test_score_ranking_top_and_bottom_and_limit(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'main' => 80]);
        $this->completedDebate($d, ['date' => '2025-03-05', 'main' => 90]);
        $this->completedDebate($d, ['date' => '2025-03-09', 'main' => 70]);

        $top = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=top&limit=2");
        $top->assertStatus(200);
        $this->assertEquals([90.0, 80.0], array_column($top->json('data.entries'), 'normalized_score'));
        $this->assertEquals(3, $top->json('data.total_qualifying_debates'));

        $bottom = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=bottom&limit=2");
        $this->assertEquals([70.0, 80.0], array_column($bottom->json('data.entries'), 'normalized_score'));

        $latest = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=latest&limit=1");
        $this->assertEquals('2025-03-09', $latest->json('data.entries.0.debate_date'));

        $earliest = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=earliest&limit=1");
        $this->assertEquals('2025-03-01', $earliest->json('data.entries.0.debate_date'));
    }

    public function test_score_ranking_exposes_reply_only_when_present(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-03-01', 'side' => 'proposition', 'main' => 80, 'reply' => 42]);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=top");
        $entry = $r->json('data.entries.0');
        $this->assertEquals(42, $entry['raw_reply_score']);
        $this->assertEqualsWithDelta(61.0, $entry['normalized_score'], 0.0001); // (80+42)/2
        $this->assertEqualsContains(['P1', 'PR'], $entry['positions_held']);
    }

    private function assertEqualsContains(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        $this->assertEquals($expected, $actual);
    }

    // ── Stat 4: improvement ────────────────────────────────────────────────────

    public function test_improvement_index_hand_computed(): void
    {
        // 3 monthly buckets, scores 60/70/80 (slope +10), all wins (winrate slope 0).
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-01-15', 'main' => 60, 'winning_side' => 'proposition', 'side' => 'proposition']);
        $this->completedDebate($d, ['date' => '2025-02-15', 'main' => 70, 'winning_side' => 'proposition', 'side' => 'proposition']);
        $this->completedDebate($d, ['date' => '2025-03-15', 'main' => 80, 'winning_side' => 'proposition', 'side' => 'proposition']);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/improvement");

        $r->assertStatus(200);
        $this->assertEquals('monthly', $r->json('data.granularity'));
        $this->assertEqualsWithDelta(10.0, $r->json('data.components.slope_score'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $r->json('data.components.slope_winrate'), 0.0001);
        $this->assertEqualsWithDelta(0.8367, $r->json('data.components.consistency'), 0.001);
        // index = 0.5*50 + 0.4*0 + 0.1*33.67 = 28.367
        $this->assertEqualsWithDelta(28.367, $r->json('data.index'), 0.05);
        $this->assertEquals('strong_upward', $r->json('data.band'));
        $this->assertCount(3, $r->json('data.buckets'));
    }

    public function test_improvement_insufficient_history(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-01-15', 'main' => 60]);
        $this->completedDebate($d, ['date' => '2025-02-15', 'main' => 70]); // only 2 buckets

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/improvement");

        $r->assertStatus(200);
        $this->assertNull($r->json('data.index'));
        $this->assertEquals('insufficient_history', $r->json('data.reason'));
        $this->assertCount(2, $r->json('data.buckets')); // partial buckets still returned
    }

    // ── Position expansion / dedupe + RANDOM bucketing ─────────────────────────

    public function test_position_side_filter_matches_slot_and_dedupes(): void
    {
        $d = $this->debater();
        // One prop debate (debater spoke P1).
        $this->completedDebate($d, ['date' => '2025-03-01', 'side' => 'proposition', 'main' => 88]);

        // Filtering by both side-level P and slot-level P1 must not double-count.
        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?positions=P,P1");
        $r->assertStatus(200);
        $this->assertEquals(1, $r->json('data.total_n_debates'));

        // Filtering by O (opposition) excludes this prop-only debate.
        $r2 = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?positions=O");
        $this->assertEquals(0, $r2->json('data.total_n_debates'));
    }

    public function test_random_team_bucketing(): void
    {
        $d = $this->debater();
        $team = Team::factory()->create(['is_random' => false, 'status' => 'active', 'name' => 'Phoenix']);
        $this->completedDebate($d, ['date' => '2025-03-01', 'team' => $team, 'main' => 80]);
        $this->completedDebate($d, ['date' => '2025-03-05', 'team' => null, 'main' => 90]); // RANDOM

        // Filter to RANDOM only → just the second debate.
        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?teams=RANDOM");
        $this->assertEquals(1, $r->json('data.total_n_debates'));

        // Filter to the real team → just the first.
        $r2 = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?teams={$team->id}");
        $this->assertEquals(1, $r2->json('data.total_n_debates'));
    }

    public function test_series_frameworks_parallel_bars(): void
    {
        $d = $this->debater();
        $fwA = MotionFramework::factory()->create(['name' => 'Political']);
        $fwB = MotionFramework::factory()->create(['name' => 'Economic']);
        $this->completedDebate($d, ['date' => '2025-03-01', 'framework_ids' => [$fwA->id], 'winning_side' => 'proposition', 'side' => 'proposition']);
        $this->completedDebate($d, ['date' => '2025-03-05', 'framework_ids' => [$fwB->id], 'winning_side' => 'opposition', 'side' => 'proposition']); // loss

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?series=frameworks&frameworks={$fwA->id},{$fwB->id}");
        $r->assertStatus(200);
        $this->assertEquals('frameworks', $r->json('data.series_dimension'));
        $series = collect($r->json('data.buckets.0.series'))->keyBy('key');
        $this->assertEqualsWithDelta(1.0, $series[(string) $fwA->id]['value'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $series[(string) $fwB->id]['value'], 0.0001);
    }

    public function test_series_falls_back_to_none_with_one_value(): void
    {
        $d = $this->debater();
        $fwA = MotionFramework::factory()->create(['name' => 'Political']);
        $this->completedDebate($d, ['date' => '2025-03-01', 'framework_ids' => [$fwA->id]]);

        // series=frameworks but only one framework selected → silently 'none'.
        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?series=frameworks&frameworks={$fwA->id}");
        $r->assertStatus(200);
        $this->assertEquals('none', $r->json('data.series_dimension'));
        $this->assertEquals('all', $r->json('data.buckets.0.series.0.key'));
    }
}
