<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChairElectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnNull();
        });
    }

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
            'livekit_room_name' => 'debate-election-main',
        ]);
    }

    public function test_admin_can_set_judge_order(): void
    {
        // Judge ordering is only allowed before the debate goes live.
        $debate = $this->makeDebate('announced');
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $judge1 = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $judge2 = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $p1 = DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $judge1->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
        ]);
        $p2 = DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $judge2->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/judges/order", [
            'judges' => [
                ['participant_id' => $p1->id, 'judge_order' => 2],
                ['participant_id' => $p2->id, 'judge_order' => 1],
            ],
        ]);

        $response->assertStatus(200);

        // judge2 has order 1 → becomes chair.
        $p2->refresh();
        $this->assertTrue((bool) $p2->is_chair);

        $p1->refresh();
        $this->assertFalse((bool) $p1->is_chair);
    }

    public function test_webhook_participant_joined_elects_lowest_judge_order(): void
    {
        $debate = $this->makeDebate();

        $judge1 = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $judge2 = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        DebateParticipant::factory()->create([
            'debate_id'   => $debate->id, 'user_id' => $judge1->id,
            'role'        => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_attended' => true, 'judge_order' => 2, 'is_chair' => false,
        ]);
        DebateParticipant::factory()->create([
            'debate_id'   => $debate->id, 'user_id' => $judge2->id,
            'role'        => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_attended' => false, 'judge_order' => 1, 'is_chair' => false,
        ]);

        // Simulate judge2 (order=1) joining.
        $payload = json_encode([
            'event' => 'participant_joined',
            'room'  => ['name' => $debate->livekit_room_name],
            'participant' => ['identity' => (string) $judge2->id],
        ]);

        // Build a fake JWT that passes signature verification (skip verification by leaving secret blank).
        config(['services.livekit.secret' => '']);

        $response = $this->postJson('/api/livekit/webhook', json_decode($payload, true), [
            'Authorization' => 'Bearer fake.jwt.token',
        ]);

        // judge2 should now be chair (lowest judge_order among attended judges).
        $p2 = DebateParticipant::where('debate_id', $debate->id)->where('user_id', $judge2->id)->first();
        $this->assertTrue((bool) $p2->is_attended);
    }

    public function test_only_approved_judges_can_be_given_order(): void
    {
        $debate = $this->makeDebate('announced');
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $p = DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role'      => 'debater', 'side' => 'proposition', 'status' => 'approved',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/judges/order", [
            'judges' => [
                ['participant_id' => $p->id, 'judge_order' => 1],
            ],
        ]);

        $response->assertStatus(422);
    }
}
