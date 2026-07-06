<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\PointsHistory;
use App\Models\Team;
use App\Models\User;
use App\Services\Points\PointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V2 §3 — the points system: Elo-style, auditable, triggered once per debate. */
class PointsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeDebateWithTeams(int $propPoints, int $oppPoints, string $winningSide, array $stages = []): array
    {
        $propTeam = Team::factory()->create(['points' => $propPoints]);
        $oppTeam  = Team::factory()->create(['points' => $oppPoints]);

        $debate = Debate::factory()->create([
            'status' => 'live',
            'proposition_team_id' => $propTeam->id,
            'opposition_team_id'  => $oppTeam->id,
        ]);

        $propDebater = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => $propPoints]);
        $oppDebater  = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => $oppPoints]);

        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $propDebater->id, 'team_id' => $propTeam->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $oppDebater->id, 'team_id' => $oppTeam->id,
            'role' => 'debater', 'side' => 'opposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);

        $defaultStages = [
            ['stage_order' => 1, 'user_id' => $propDebater->id, 'score' => 70],
            ['stage_order' => 2, 'user_id' => $oppDebater->id, 'score' => 70],
        ];

        DebateResult::create([
            'debate_id'    => $debate->id,
            'judge_id'     => User::factory()->create(['role' => 'judge', 'status' => 'active'])->id,
            'winning_side' => $winningSide,
            'scores'       => ['stages' => $stages ?: $defaultStages, 'notes' => null],
            'submitted_at' => now(),
        ]);

        return [$debate->fresh(['result']), $propTeam, $oppTeam, $propDebater, $oppDebater];
    }

    public function test_winner_gains_and_loser_loses_points(): void
    {
        [$debate, $propTeam, $oppTeam, $propDebater, $oppDebater] = $this->makeDebateWithTeams(0, 0, 'proposition');

        app(PointsService::class)->awardForDebate($debate);

        $this->assertGreaterThan(0, $propDebater->fresh()->points);
        $this->assertGreaterThan($oppDebater->fresh()->points, $propDebater->fresh()->points);
        $this->assertGreaterThan(0, $propTeam->fresh()->points);
        $this->assertLessThanOrEqual(0, $oppTeam->fresh()->points - $propTeam->fresh()->points);
    }

    public function test_beating_a_higher_rated_opponent_awards_more_than_beating_a_lower_rated_one(): void
    {
        // Case A: prop (rating 0) beats a MUCH stronger opp (rating 400).
        [$debateA, $teamAProp] = $this->makeDebateWithTeams(0, 400, 'proposition');
        app(PointsService::class)->awardForDebate($debateA);
        $deltaVsStronger = $teamAProp->fresh()->points - 0;

        // Case B: prop (rating 0) beats a MUCH weaker opp (rating -400).
        [$debateB, $teamBProp] = $this->makeDebateWithTeams(0, -400, 'proposition');
        app(PointsService::class)->awardForDebate($debateB);
        $deltaVsWeaker = $teamBProp->fresh()->points - 0;

        $this->assertGreaterThan($deltaVsWeaker, $deltaVsStronger);
    }

    public function test_draw_awards_half_actual_score_to_both_sides(): void
    {
        [$debate, $propTeam, $oppTeam] = $this->makeDebateWithTeams(0, 0, 'draw');

        app(PointsService::class)->awardForDebate($debate);

        // Symmetric ratings + draw → expected 0.5 both ways → elo delta ~ 0 for both.
        $this->assertEquals($propTeam->fresh()->points, $oppTeam->fresh()->points);
    }

    public function test_awarding_is_idempotent_per_debate(): void
    {
        [$debate] = $this->makeDebateWithTeams(0, 0, 'proposition');

        app(PointsService::class)->awardForDebate($debate);
        $countAfterFirst = PointsHistory::count();
        $pointsAfterFirst = User::where('role', 'debater')->orderBy('id')->first()->points;

        app(PointsService::class)->awardForDebate($debate->fresh(['result']));

        $this->assertEquals($countAfterFirst, PointsHistory::count());
        $this->assertEquals($pointsAfterFirst, User::where('role', 'debater')->orderBy('id')->first()->points);
    }

    public function test_points_history_is_auditable_with_breakdown(): void
    {
        [$debate, $propTeam] = $this->makeDebateWithTeams(0, 0, 'proposition');

        app(PointsService::class)->awardForDebate($debate);

        $history = PointsHistory::where('subject_type', Team::class)->where('subject_id', $propTeam->id)->first();
        $this->assertNotNull($history);
        $this->assertEquals($debate->id, $history->debate_id);
        $this->assertEquals(0, $history->points_before);
        $this->assertEquals($history->points_before + $history->delta, $history->points_after);
        $this->assertArrayHasKey('elo_expected', $history->breakdown);
        $this->assertArrayHasKey('opponent_rating', $history->breakdown);
        $this->assertArrayHasKey('score_component', $history->breakdown);
    }

    public function test_points_never_go_below_the_configured_minimum(): void
    {
        config(['debate.points.min_points' => 0]);
        // A team with 0 points losing badly must not go negative.
        [$debate, $propTeam] = $this->makeDebateWithTeams(0, 1000, 'opposition');

        app(PointsService::class)->awardForDebate($debate);

        $this->assertGreaterThanOrEqual(0, $propTeam->fresh()->points);
    }

    public function test_close_room_endpoint_triggers_points_award(): void
    {
        $format = \App\Models\DebateFormat::factory()->create([
            'phase_config' => ['speech_time_seconds' => 300, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5],
        ]);
        $propTeam = Team::factory()->create(['points' => 0]);
        $oppTeam  = Team::factory()->create(['points' => 0]);
        $debate = Debate::factory()->create([
            'format_id' => $format->id, 'status' => 'live',
            'proposition_team_id' => $propTeam->id, 'opposition_team_id' => $oppTeam->id,
            'livekit_room_name' => 'debate-pts-main', 'result_room_name' => 'debate-pts-result',
        ]);
        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved', 'is_chair' => true, 'judge_order' => 1,
        ]);
        $propDebater = User::factory()->create(['role' => 'debater', 'status' => 'active', 'points' => 0]);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $propDebater->id, 'team_id' => $propTeam->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);
        DebateResult::create([
            'debate_id' => $debate->id, 'judge_id' => $chair->id, 'winning_side' => 'proposition',
            'scores' => ['stages' => [['stage_order' => 1, 'user_id' => $propDebater->id, 'score' => 80]], 'notes' => null],
            'submitted_at' => now(),
        ]);

        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
            $mock->shouldReceive('deleteRoomIfExists')->andReturnNull();
        });

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(200);

        $this->assertGreaterThan(0, $propDebater->fresh()->points);
        $this->assertGreaterThan(0, $propTeam->fresh()->points);
        $this->assertDatabaseHas('points_histories', ['debate_id' => $debate->id]);
    }
}
