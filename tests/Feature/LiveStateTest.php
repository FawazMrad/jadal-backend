<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeDebate(string $status = 'live'): Debate
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

        return Debate::factory()->create([
            'format_id'        => $format->id,
            'status'           => $status,
            'current_stage'    => 0,
            'livekit_room_name' => 'debate-test-main',
            'prop_room_name'   => 'debate-test-prop',
            'opp_room_name'    => 'debate-test-opp',
            'result_room_name' => 'debate-test-result',
        ]);
    }

    public function test_non_participant_can_access_live_state_as_viewer(): void
    {
        // V2: live-state is open to any authenticated user. Sensitive bits are
        // hidden inside the payload, not via a 403.
        $debate = $this->makeDebate();
        $user   = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($user)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        // A non-participant can still join the main room as a viewer.
        $this->assertTrue($response->json('data.rooms.main.joinable_for_me'));
    }

    public function test_admin_can_access_live_state_without_being_participant(): void
    {
        $debate = $this->makeDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'debate'      => ['id', 'status', 'current_stage'],
                    'format'      => ['speech_time_seconds', 'has_reply_speech', 'speakers_per_side', 'total_stages'],
                    'rooms'       => ['main', 'prop', 'opp', 'result'],
                    'judges',
                    'proposition',
                    'opposition',
                    'stages',
                ],
            ]);
    }

    public function test_approved_participant_sees_correct_room_open_flags(): void
    {
        $debate = $this->makeDebate('live');
        $debate->update(['current_stage' => 1]);

        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $user->id,
            'role'      => 'debater',
            'side'      => 'proposition',
            'status'    => 'approved',
        ]);

        $response = $this->actingAs($user)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data['rooms']['main']['open']);
    }

    public function test_motion_hidden_before_reveal_time(): void
    {
        $debate = $this->makeDebate('scheduled');
        // motion_revealed_at is null by default. Admins always see the motion, so
        // use a non-admin participant to verify the pre-reveal hiding.
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $debater->id,
            'role'      => 'debater',
            'side'      => 'proposition',
            'status'    => 'approved',
        ]);

        $response = $this->actingAs($debater)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.motion'));
    }

    public function test_motion_visible_after_reveal(): void
    {
        $debate = $this->makeDebate('announced');
        $debate->update(['motion_revealed_at' => now()->subMinute()]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson("/api/debates/{$debate->id}/live-state");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.motion'));
    }
}
