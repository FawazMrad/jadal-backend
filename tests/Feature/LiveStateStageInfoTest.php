<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveStateStageInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_state_exposes_current_stage_start_and_speaker(): void
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 420, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id'         => $format->id,
            'status'            => 'live',
            'current_stage'     => 1,
            'livekit_room_name' => 'debate-73-main',
        ]);

        $speaker = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $speakerP = DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $speaker->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
            'speaking_phase_order' => 1,
        ]);

        $startedAt = Carbon::parse('2025-04-12T10:00:00Z');
        DebatePhase::factory()->create([
            'debate_id' => $debate->id, 'name' => 'Proposition 1', 'order_index' => 1,
            'status' => 'active', 'started_at' => $startedAt, 'participant_id' => $speakerP->id,
            'duration_seconds' => 420, 'is_reply' => false,
        ]);

        $response = $this->actingAs($speaker)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        // current_stage_started_at present (timer sync for late joiners).
        $this->assertNotNull($response->json('data.debate.current_stage_started_at'));
        // Stage 1 carries a resolvable speaker_user_id.
        $stage = collect($response->json('data.stages'))->firstWhere('order_index', 1);
        $this->assertEquals($speaker->id, $stage['speaker_user_id']);
        $this->assertEquals($speakerP->id, $stage['participant_id']);
    }

    public function test_current_stage_started_at_is_null_in_lobby(): void
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 420, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
        $debate = Debate::factory()->create([
            'format_id' => $format->id, 'status' => 'live', 'current_stage' => 0,
            'livekit_room_name' => 'debate-73-main',
        ]);
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $user->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->getJson("/api/debates/{$debate->id}/live-state");
        $response->assertStatus(200);
        $this->assertNull($response->json('data.debate.current_stage_started_at'));
    }
}
