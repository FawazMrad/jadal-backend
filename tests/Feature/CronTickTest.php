<?php

namespace Tests\Feature;

use App\Console\Commands\AdvanceDebatesLifecycle;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CronTickTest extends TestCase
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

    private function makeFormat(bool $hasReply = false): DebateFormat
    {
        return DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => $hasReply,
                'reply_time_seconds'           => $hasReply ? 240 : 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    public function test_motion_revealed_when_reveal_time_passed(): void
    {
        $format = $this->makeFormat();
        // V2: status flip to 'announced' is admin-driven, so this debate is already
        // 'announced'. The cron only reveals the motion (status untouched).
        // scheduled in 30min, reveal offset = 1h → reveal time = 30min ago → triggers.
        $debate = Debate::factory()->create([
            'format_id'    => $format->id,
            'status'       => 'announced',
            'scheduled_at' => now()->addMinutes(30),
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertNotNull($debate->motion_revealed_at);
        $this->assertEquals('announced', $debate->status);
    }

    public function test_prep_rooms_opened_at_right_time(): void
    {
        $format = $this->makeFormat();
        // scheduled 1h ago, prep offset = 0.5h → open time = 30min ago → should trigger.
        $debate = Debate::factory()->create([
            'format_id'    => $format->id,
            'status'       => 'announced',
            'scheduled_at' => now()->subHour(),
            'motion_revealed_at' => now()->subHour(),
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertNotNull($debate->prep_rooms_opened_at);
    }

    public function test_debate_advances_to_live_at_scheduled_time_with_judge(): void
    {
        $format = $this->makeFormat();
        $debate = Debate::factory()->create([
            'format_id'           => $format->id,
            'status'              => 'teams-selected',
            'scheduled_at'        => now()->subMinutes(5),
            'motion_revealed_at'  => now()->subHour(),
            'prep_rooms_opened_at' => now()->subMinutes(30),
        ]);

        $judge = User::factory()->create(['role' => 'judge']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $judge->id,
            'role'      => 'judge',
            'side'      => 'judge',
            'status'    => 'approved',
        ]);

        // Add 3 prop and 3 opp approved debaters.
        foreach (['proposition', 'opposition'] as $side) {
            for ($i = 0; $i < 3; $i++) {
                $u = User::factory()->create(['role' => 'debater']);
                DebateParticipant::factory()->create([
                    'debate_id' => $debate->id,
                    'user_id'   => $u->id,
                    'role'      => 'debater',
                    'side'      => $side,
                    'status'    => 'approved',
                ]);
            }
        }

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertEquals('live', $debate->status);
        $this->assertNotNull($debate->started_at);
    }

    public function test_debate_cancelled_if_no_approved_judge(): void
    {
        $format = $this->makeFormat();
        $debate = Debate::factory()->create([
            'format_id'           => $format->id,
            'status'              => 'teams-selected',
            'scheduled_at'        => now()->subMinutes(5),
            'motion_revealed_at'  => now()->subHour(),
            'prep_rooms_opened_at' => now()->subMinutes(30),
        ]);

        $this->artisan('debates:tick');

        $debate->refresh();
        $this->assertEquals('cancelled', $debate->status);
    }

    public function test_auto_assigns_speakers_if_slots_empty(): void
    {
        $format = $this->makeFormat();
        $debate = Debate::factory()->create([
            'format_id'           => $format->id,
            'status'              => 'teams-selected',
            'scheduled_at'        => now()->subMinutes(5),
            'motion_revealed_at'  => now()->subHour(),
            'prep_rooms_opened_at' => now()->subMinutes(30),
        ]);

        $judge = User::factory()->create(['role' => 'judge']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $judge->id,
            'role'      => 'judge',
            'side'      => 'judge',
            'status'    => 'approved',
        ]);

        foreach (['proposition', 'opposition'] as $side) {
            for ($i = 0; $i < 3; $i++) {
                $u = User::factory()->create(['role' => 'debater']);
                DebateParticipant::factory()->create([
                    'debate_id'            => $debate->id,
                    'user_id'              => $u->id,
                    'role'                 => 'debater',
                    'side'                 => $side,
                    'status'               => 'approved',
                    'speaking_phase_order' => null, // unset
                ]);
            }
        }

        $this->artisan('debates:tick');

        $debate->refresh();

        // All 3 slots should be filled for each side.
        foreach (['proposition', 'opposition'] as $side) {
            $assigned = DebateParticipant::where('debate_id', $debate->id)
                ->where('side', $side)
                ->whereNotNull('speaking_phase_order')
                ->count();
            $this->assertEquals(3, $assigned, "Expected 3 assigned speakers for {$side}");
        }
    }

    public function test_phases_created_when_debate_goes_live(): void
    {
        $format = $this->makeFormat();
        $debate = Debate::factory()->create([
            'format_id'           => $format->id,
            'status'              => 'teams-selected',
            'scheduled_at'        => now()->subMinutes(5),
            'motion_revealed_at'  => now()->subHour(),
            'prep_rooms_opened_at' => now()->subMinutes(30),
        ]);

        $judge = User::factory()->create(['role' => 'judge']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $judge->id,
            'role'      => 'judge',
            'side'      => 'judge',
            'status'    => 'approved',
        ]);

        foreach (['proposition', 'opposition'] as $side) {
            for ($i = 0; $i < 3; $i++) {
                $u = User::factory()->create(['role' => 'debater']);
                DebateParticipant::factory()->create([
                    'debate_id' => $debate->id,
                    'user_id'   => $u->id,
                    'role'      => 'debater',
                    'side'      => $side,
                    'status'    => 'approved',
                ]);
            }
        }

        $this->artisan('debates:tick');

        $phaseCount = DebatePhase::where('debate_id', $debate->id)->count();
        $this->assertEquals(6, $phaseCount); // no reply → 6 phases
    }
}
