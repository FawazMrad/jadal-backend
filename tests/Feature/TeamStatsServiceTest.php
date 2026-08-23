<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Team;
use App\Models\User;
use App\Services\Stats\StatsFilter;
use App\Services\Stats\TeamStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** No team-scoped stats API existed before this. */
class TeamStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function completedDebateForTeam(Team $team, string $side, string $winningSide, ?float $score, string $date = '2025-03-01'): void
    {
        $opponentSide = $side === 'proposition' ? 'opposition' : 'proposition';
        $opponentTeam = Team::factory()->create();

        $debate = Debate::factory()->create([
            'status' => 'completed',
            'scheduled_at' => $date,
            'result_revealed_at' => now(),
            'proposition_team_id' => $side === 'proposition' ? $team->id : $opponentTeam->id,
            'opposition_team_id'  => $side === 'opposition' ? $team->id : $opponentTeam->id,
        ]);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $debater->id, 'team_id' => $team->id,
            'role' => 'debater', 'side' => $side, 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);

        DebateResult::create([
            'debate_id' => $debate->id,
            'judge_id'  => User::factory()->create(['role' => 'judge', 'status' => 'active'])->id,
            'winning_side' => $winningSide,
            'scores' => ['stages' => $score === null ? [] : [
                ['stage_order' => 1, 'user_id' => $debater->id, 'score' => $score],
            ], 'notes' => null],
            'submitted_at' => now(),
        ]);
    }

    public function test_win_rate_and_avg_score(): void
    {
        $team = Team::factory()->create();
        $this->completedDebateForTeam($team, 'proposition', 'proposition', 80);
        $this->completedDebateForTeam($team, 'opposition', 'proposition', 60); // this team lost (was opposition)

        $service = app(TeamStatsService::class);
        $f = StatsFilter::fromArray([]);

        $this->assertEquals(0.5, $service->winRate($team, $f));
        $this->assertEquals(70.0, $service->avgScore($team, $f));
    }

    public function test_draw_counts_as_half_a_win(): void
    {
        $team = Team::factory()->create();
        $this->completedDebateForTeam($team, 'proposition', 'draw', 70);

        $service = app(TeamStatsService::class);
        $this->assertEquals(0.5, $service->winRate($team, StatsFilter::fromArray([])));
    }

    public function test_win_rate_null_when_no_eligible_debates(): void
    {
        $team = Team::factory()->create();
        $this->assertNull(app(TeamStatsService::class)->winRate($team, StatsFilter::fromArray([])));
    }

    public function test_date_range_filters_participation_rows(): void
    {
        $team = Team::factory()->create();
        $this->completedDebateForTeam($team, 'proposition', 'proposition', 80, '2024-01-10');
        $this->completedDebateForTeam($team, 'proposition', 'opposition', 60, '2025-06-10'); // loss, out of range

        $service = app(TeamStatsService::class);
        $f = StatsFilter::fromArray(['from' => '2024-01', 'to' => '2024-12']);

        $this->assertEquals(1.0, $service->winRate($team, $f)); // only the 2024 win counted
    }
}
