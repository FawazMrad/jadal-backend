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

/** V2 §3 — coach team-summary. */
class CoachTeamSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_averages_across_coachs_teams(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team = Team::factory()->create(['created_by' => $coach->id, 'is_random' => false, 'status' => 'active']);

        $member = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        TeamMember::create(['team_id' => $team->id, 'user_id' => $member->id, 'priority' => 1, 'status' => 'current']);

        $opponent = Team::factory()->create();
        $debate = Debate::factory()->create([
            'status' => 'completed', 'scheduled_at' => now()->subDays(5), 'result_revealed_at' => now(),
            'proposition_team_id' => $team->id, 'opposition_team_id' => $opponent->id,
        ]);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $member->id, 'team_id' => $team->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);
        DebateResult::create([
            'debate_id' => $debate->id,
            'judge_id' => User::factory()->create(['role' => 'judge', 'status' => 'active'])->id,
            'winning_side' => 'proposition',
            'scores' => ['stages' => [['stage_order' => 1, 'user_id' => $member->id, 'score' => 80]], 'notes' => null],
            'submitted_at' => now(),
        ]);

        $res = $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/stats/team-summary");
        $res->assertStatus(200);
        $res->assertJsonPath('data.teams_counted', 1);
        $res->assertJsonPath('data.team_avg_win_rate', 1);
        $res->assertJsonPath('data.team_avg_score', 80);
    }

    /**
     * Frontend spec §6.4 — statistics are public, so the coach team summary is
     * readable by any authenticated user. (This test previously asserted the
     * stranger got a 403 when the coach had opted out.)
     */
    public function test_summary_is_public_to_any_authenticated_user(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($stranger)->getJson("/api/trainers/{$coach->id}/stats/team-summary")->assertStatus(200);
        $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/stats/team-summary")->assertStatus(200);
    }

    public function test_summary_with_no_teams_returns_null_metrics(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $res = $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/stats/team-summary");
        $res->assertStatus(200);
        $res->assertJsonPath('data.teams_counted', 0);
        $res->assertJsonPath('data.team_avg_win_rate', null);
    }
}
