<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\DebateResult;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContributingJudgesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // submitResult does not call LiveKit, but the controller depends on the
        // service — bind a mock so the real constructor (which needs config) is skipped.
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    public function test_submitting_result_captures_all_attended_judges(): void
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        // Result phase: speeches done, still `live` → the chair may submit a result.
        $debate = Debate::factory()->create([
            'format_id' => $format->id,
            'status'    => 'live',
            'speeches_completed_at' => now(),
        ]);

        // 6 phases so the stage_scores count matches.
        foreach (range(1, 6) as $i) {
            DebatePhase::factory()->create([
                'debate_id'   => $debate->id,
                'name'        => "Stage {$i}",
                'order_index' => $i,
                'status'      => 'completed',
            ]);
        }

        // Chair + one panel judge attended; one panel judge NOT attended.
        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1, 'is_attended' => true,
        ]);

        $panelAttended = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panelAttended->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2, 'is_attended' => true,
        ]);

        $panelAbsent = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panelAbsent->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 3, 'is_attended' => false,
        ]);

        $stageScores = [];
        foreach (range(1, 6) as $i) {
            $stageScores[] = ['stage_order' => $i, 'score' => 70 + $i];
        }

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/result", [
            'winning_side' => 'proposition',
            'summary_notes' => 'Test.',
            'stage_scores' => $stageScores,
        ]);

        $response->assertStatus(201);

        $result = DebateResult::where('debate_id', $debate->id)->first();
        $this->assertNotNull($result->contributing_judges);

        $userIds = collect($result->contributing_judges)->pluck('user_id')->all();
        $this->assertContains($chair->id, $userIds);
        $this->assertContains($panelAttended->id, $userIds);
        $this->assertNotContains($panelAbsent->id, $userIds);
        $this->assertCount(2, $userIds);

        // The response resource exposes contributing_judges too.
        $response->assertJsonPath('data.contributing_judges.0.user_id', fn ($v) => is_int($v));
    }
}
