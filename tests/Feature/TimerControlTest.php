<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * server-authoritative timer: chair pause/resume persists and the
 * fields are exposed in live-state so any (re)joiner restores the exact clock.
 */
class TimerControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

    /** @return array{0:Debate,1:User} active speech (stage 2), chair attended */
    private function activeSpeechDebate(int $startedSecondsAgo = 30): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 480, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id' => $format->id,
            'status' => 'live',
            'current_stage' => 2,
            'livekit_room_name' => 'debate-timer-main',
        ]);

        DebatePhase::factory()->create([
            'debate_id' => $debate->id, 'order_index' => 2, 'name' => 'Opposition 1',
            'status' => 'active', 'is_reply' => false,
            'started_at' => now()->subSeconds($startedSecondsAgo),
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1, 'is_attended' => true,
        ]);

        return [$debate, $chair];
    }

    public function test_chair_can_pause_timer(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate(30);

        $res = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.debate.timer_is_paused', true);

        $debate->refresh();
        $this->assertTrue($debate->timer_is_paused);
        $this->assertGreaterThanOrEqual(29, $debate->timer_paused_elapsed_seconds);
    }

    public function test_resume_continues_from_frozen_elapsed(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate(30);

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause'])->assertStatus(200);
        $frozen = (int) $debate->fresh()->timer_paused_elapsed_seconds;

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'resume'])->assertStatus(200);

        $debate->refresh();
        $this->assertFalse($debate->timer_is_paused);
        $this->assertEquals(0, $debate->timer_paused_elapsed_seconds);

        // The active phase start was rebased so elapsed continues from ~frozen.
        $phase = DebatePhase::where('debate_id', $debate->id)->where('order_index', 2)->first();
        $elapsedNow = now()->getTimestamp() - $phase->started_at->getTimestamp();
        $this->assertEqualsWithDelta($frozen, $elapsedNow, 2);
    }

    public function test_pause_is_idempotent_keeps_first_frozen_value(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate(30);

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause'])->assertStatus(200);
        $first = (int) $debate->fresh()->timer_paused_elapsed_seconds;

        // A second pause must NOT re-stamp a larger elapsed.
        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause'])->assertStatus(200);
        $this->assertEquals($first, (int) $debate->fresh()->timer_paused_elapsed_seconds);
    }

    public function test_non_chair_cannot_control_timer(): void
    {
        [$debate] = $this->activeSpeechDebate();
        $panel = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $panel->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->actingAs($panel)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause'])->assertStatus(403);
    }

    public function test_cannot_control_timer_with_no_active_speech(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate();
        $debate->update(['current_stage' => 0]); // lobby — no speech

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'pause'])->assertStatus(422);
    }

    public function test_invalid_action_is_rejected(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate();
        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/timer", ['action' => 'frobnicate'])->assertStatus(422);
    }

    public function test_live_state_exposes_timer_fields(): void
    {
        [$debate, $chair] = $this->activeSpeechDebate();

        $res = $this->actingAs($chair)->getJson("/api/debates/{$debate->id}/live-state");
        $res->assertStatus(200);
        $res->assertJsonPath('data.debate.timer_is_paused', false);
        $this->assertIsInt($res->json('data.debate.timer_paused_elapsed_seconds'));
        $this->assertNotNull($res->json('data.debate.server_now'));
    }
}
