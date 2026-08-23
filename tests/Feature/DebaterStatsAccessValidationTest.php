<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Motion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCompletedDebates;
use Tests\TestCase;

class DebaterStatsAccessValidationTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedDebates;

    // ── Access control matrix ──────────────────────────────────────────────────

    public function test_debater_can_view_own_stats(): void
    {
        $d = $this->debater();
        $this->completedDebate($d);
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
    }

    /** Stats are public by default: any authenticated stranger can view them. */
    public function test_stranger_can_view_stats_by_default(): void
    {
        $d = $this->debater();
        $other = $this->debater();
        $this->actingAs($other)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
    }

    public function test_coach_can_view_supervised_debater(): void
    {
        $d = $this->debater();
        $coach = $this->coachWithSupervisee($d);
        $this->actingAs($coach)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
    }

    /** An unrelated coach is just "any other user": public by default too. */
    public function test_unsupervising_coach_can_view_stats_by_default(): void
    {
        $d = $this->debater();
        $strangerCoach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->actingAs($strangerCoach)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
    }

    public function test_admin_can_view_anyone(): void
    {
        $d = $this->debater();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
    }

    /**
     * The stats_visible opt-out is removed and statistics
     * are public, so every role reads the same 200. (This test previously
     * asserted a stranger got 403 when the debater had opted out.)
     */
    public function test_stats_are_public_to_every_authenticated_role(): void
    {
        $d        = $this->debater();
        $coach    = $this->coachWithSupervisee($d);
        $admin    = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $stranger = $this->debater();

        foreach ([$stranger, $d, $admin, $coach] as $viewer) {
            $this->actingAs($viewer)->getJson("/api/debaters/{$d->id}/stats/win-rate")->assertStatus(200);
        }
    }

    /**
     * These metrics are debating-performance only, so a judge or
     * trainer SUBJECT is rejected rather than returning empty aggregates that
     * read as "this judge has a 0% win rate".
     */
    public function test_non_debater_subject_is_rejected(): void
    {
        $viewer  = $this->debater();
        $judge   = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        foreach ([$judge, $trainer] as $subject) {
            foreach (['win-rate', 'avg-score', 'best-speaker', 'improvement'] as $endpoint) {
                $this->actingAs($viewer)
                    ->getJson("/api/debaters/{$subject->id}/stats/{$endpoint}")
                    ->assertStatus(422);
            }
        }
    }

    /** Position and framework filters may never be combined. */
    public function test_positions_and_frameworks_are_mutually_exclusive(): void
    {
        $d = $this->debater();

        $this->actingAs($d)
            ->getJson("/api/debaters/{$d->id}/stats/win-rate?positions=P1&frameworks=1")
            ->assertStatus(422)
            ->assertJsonValidationErrors('positions');

        // Either one alone is still fine.
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?positions=P1")->assertStatus(200);
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?frameworks=1")->assertStatus(200);
    }

    // ── Validation rules ───────────────────────────────────────────────────────

    public function test_best_speaker_rejects_reply_positions(): void
    {
        $d = $this->debater();
        foreach (['R', 'PR', 'OR'] as $code) {
            $this->actingAs($d)
                ->getJson("/api/debaters/{$d->id}/stats/best-speaker?positions={$code}")
                ->assertStatus(422);
        }
        // Non-reply codes are fine.
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/best-speaker?positions=P1,O2")->assertStatus(200);
    }

    public function test_score_ranking_requires_ranking_mode(): void
    {
        $d = $this->debater();
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking")->assertStatus(422);
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=bogus")->assertStatus(422);
    }

    public function test_limit_capped_at_50(): void
    {
        $d = $this->debater();
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/score-ranking?ranking_mode=top&limit=51")->assertStatus(422);
    }

    public function test_invalid_position_code_rejected(): void
    {
        $d = $this->debater();
        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?positions=ZZ")->assertStatus(422);
    }

    public function test_group_by_month_span_guard(): void
    {
        $d = $this->debater();
        // 36-month span, no from/to → rejected.
        $this->completedDebate($d, ['date' => '2022-01-10']);
        $this->completedDebate($d, ['date' => '2025-01-10']);

        $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate?group_by=month")->assertStatus(422);

        // With explicit from/to → allowed.
        $this->actingAs($d)
            ->getJson("/api/debaters/{$d->id}/stats/win-rate?group_by=month&from=2022-01&to=2025-02")
            ->assertStatus(200);
    }

    // ── Exclusions ─────────────────────────────────────────────────────────────

    public function test_non_completed_and_unrevealed_debates_excluded(): void
    {
        $d = $this->debater();

        // A proper completed debate (counts).
        $this->completedDebate($d, ['date' => '2025-03-01']);

        // Completed but result not revealed → excluded.
        $unrevealed = $this->completedDebate($d, ['date' => '2025-03-02']);
        $unrevealed->update(['result_revealed_at' => null]);

        // Not completed (live) → excluded.
        $live = $this->completedDebate($d, ['date' => '2025-03-03']);
        $live->update(['status' => 'live']);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate");
        $this->assertEquals(1, $r->json('data.total_n_debates'));
    }

    public function test_debate_with_no_stage_scores_excluded(): void
    {
        $d = $this->debater();
        $motion = Motion::factory()->create();
        $debate = Debate::factory()->create([
            'status' => 'completed', 'motion_id' => $motion->id,
            'scheduled_at' => Carbon::parse('2025-03-01'),
            'result_revealed_at' => Carbon::parse('2025-03-01')->addHour(),
        ]);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $d->id, 'role' => 'debater',
            'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => true,
        ]);
        DebateResult::create([
            'debate_id' => $debate->id, 'judge_id' => $this->judgeUser()->id,
            'winning_side' => 'proposition', 'scores' => ['stages' => [], 'notes' => null],
            'submitted_at' => Carbon::parse('2025-03-01')->addHour(),
        ]);

        $r = $this->actingAs($d)->getJson("/api/debaters/{$d->id}/stats/win-rate");
        $this->assertEquals(0, $r->json('data.total_n_debates'));
    }

    // ── Empty bucket omission ──────────────────────────────────────────────────

    public function test_empty_month_buckets_are_omitted(): void
    {
        $d = $this->debater();
        $this->completedDebate($d, ['date' => '2025-01-10']);
        $this->completedDebate($d, ['date' => '2025-03-10']); // skip February

        $r = $this->actingAs($d)
            ->getJson("/api/debaters/{$d->id}/stats/win-rate?group_by=month&from=2025-01&to=2025-03");
        $r->assertStatus(200);
        $labels = array_column($r->json('data.buckets'), 'label');
        $this->assertEquals(['2025-01', '2025-03'], $labels);
    }
}
