<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseRoomTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> ordered log of LiveKit calls */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('sendDataToRoom')->andReturnUsing(function ($room, $payload) {
                $this->calls[] = 'send:' . ($payload['event'] ?? '') . '@' . $room;
            });
            $mock->shouldReceive('deleteRoomIfExists')->andReturnUsing(function ($room) {
                $this->calls[] = 'delete:' . $room;
            });
        });
    }

    private function makeDebate(string $status): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds' => 300, 'has_reply_speech' => false, 'reply_time_seconds' => 0,
                'motion_reveal_offset_hours' => 1, 'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id'         => $format->id,
            'status'            => $status,
            'livekit_room_name' => 'debate-cr-main',
            'result_room_name'  => 'debate-cr-result',
        ]);

        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        return [$debate, $chair];
    }

    public function test_chair_closing_live_debate_without_result_cancels(): void
    {
        [$debate, $chair] = $this->makeDebate('live');

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room");

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertEquals('cancelled', $debate->status);
        $this->assertEquals('manual', $debate->cancellation_reason);
        $this->assertNull($debate->result_revealed_at);
    }

    public function test_chair_closing_result_phase_with_stored_result_completes_not_cancels(): void
    {
        // Result phase: speeches done, still `live`, a result is stored but not
        // yet revealed. Closing must COMPLETE (not cancel) and reveal it.
        [$debate, $chair] = $this->makeDebate('live');
        $debate->update(['speeches_completed_at' => now(), 'result_revealed_at' => null]);
        DebateResult::factory()->create([
            'debate_id' => $debate->id, 'judge_id' => $chair->id, 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room");

        $response->assertStatus(200);
        $debate->refresh();
        // A finished result must NOT be cancelled — the debate is completed + revealed.
        $this->assertEquals('completed', $debate->status);
        $this->assertNotNull($debate->result_revealed_at);
        $this->assertContains('send:result_revealed@debate-cr-result', $this->calls);
    }

    public function test_room_closed_broadcasts_before_room_deletion(): void
    {
        [$debate, $chair] = $this->makeDebate('live');

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(200);

        $broadcastIdx = array_search('send:room_closed@debate-cr-main', $this->calls, true);
        $deleteIdx    = array_search('delete:debate-cr-main', $this->calls, true);

        $this->assertNotFalse($broadcastIdx, 'room_closed was not broadcast');
        $this->assertNotFalse($deleteIdx, 'main room was not deleted');
        $this->assertLessThan($deleteIdx, $broadcastIdx, 'room_closed must broadcast BEFORE the room is deleted');
    }

    public function test_non_chair_cannot_close_room(): void
    {
        [$debate, $chair] = $this->makeDebate('live');
        $other = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $other->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => false, 'judge_order' => 2,
        ]);

        $this->actingAs($other)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(403);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($debater)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(403);
    }

    public function test_can_close_from_teams_selected(): void
    {
        [$debate, $chair] = $this->makeDebate('teams-selected');

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(200);
        $this->assertEquals('cancelled', $debate->fresh()->status);
    }

    public function test_already_cancelled_is_rejected(): void
    {
        [$debate, $chair] = $this->makeDebate('cancelled');

        $this->actingAs($chair)->postJson("/api/debates/{$debate->id}/close-room")->assertStatus(422);
    }
}
