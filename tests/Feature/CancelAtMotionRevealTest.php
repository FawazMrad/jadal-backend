<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelAtMotionRevealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('provisionDebateRooms')->andReturnNull();
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
        });
    }

    private function makeFormat(): DebateFormat
    {
        return DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    /**
     * The no-participants auto-cancel is currently commented out in
     * AdvanceDebatesLifecycle (both the motion-reveal and the start-time site),
     * so a debate nobody registered for now stays `scheduled`. The motion is
     * still revealed on schedule.
     *
     * When that code is re-enabled, restore the cancellation assertions kept
     * below.
     */
    public function test_scheduled_debate_with_no_participants_is_not_cancelled_at_motion_reveal(): void
    {
        $format = $this->makeFormat();
        // Motion-reveal time = scheduled_at - 1h. Scheduled in 30min → reveal time passed.
        $debate = Debate::factory()->create([
            'format_id'    => $format->id,
            'status'       => 'scheduled',
            'scheduled_at' => now()->addMinutes(30),
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertEquals('scheduled', $debate->status);
        $this->assertNull($debate->cancellation_reason);
        $this->assertNotNull($debate->motion_revealed_at);

        // Assertions for when auto-cancel is switched back on:
        // $this->assertEquals('cancelled', $debate->status);
        // $this->assertEquals('no_participants_at_motion_reveal', $debate->cancellation_reason);
    }

    public function test_announced_debate_is_not_cancelled_at_motion_reveal(): void
    {
        $format = $this->makeFormat();
        $debate = Debate::factory()->create([
            'format_id'    => $format->id,
            'status'       => 'announced',
            'scheduled_at' => now()->addMinutes(30),
        ]);

        // Give it participants so it is a real debate.
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $judge->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertEquals('announced', $debate->status);
        $this->assertNull($debate->cancellation_reason);
        $this->assertNotNull($debate->motion_revealed_at);
    }

    public function test_no_judge_at_scheduled_time_cancels_with_judge_reason(): void
    {
        $format = $this->makeFormat();
        // Past scheduled_at, status teams-selected, but no judge.
        $debate = Debate::factory()->create([
            'format_id'            => $format->id,
            'status'               => 'teams-selected',
            'scheduled_at'         => now()->subMinutes(5),
            'motion_revealed_at'   => now()->subHour(),
            'prep_rooms_opened_at' => now()->subMinutes(30),
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertEquals('cancelled', $debate->status);
        $this->assertEquals('no_judge_at_scheduled', $debate->cancellation_reason);
    }
}
