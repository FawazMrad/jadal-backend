<?php

namespace Tests\Feature;

use App\Console\Commands\AdvanceDebatesLifecycle;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\Team;
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

    /** @return list<int> the created debater user ids, pooled onto $teamId with side=null/status=pending (mirrors announce()'s output). */
    private function makePendingTeamPool(Debate $debate, int $teamId, int $count = 3): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $u = User::factory()->create(['role' => 'debater']);
            DebateParticipant::factory()->create([
                'debate_id' => $debate->id,
                'user_id'   => $u->id,
                'team_id'   => $teamId,
                'role'      => 'debater',
                'side'      => null,
                'status'    => 'pending',
            ]);
            $ids[] = $u->id;
        }

        return $ids;
    }

    public function test_sides_randomly_assigned_and_teams_selected_reached(): void
    {
        // Reproduces the announce() -> debates:tick gap: announce() pools each
        // team with side=null/status=pending and never sets a side itself: only
        // this tick (at the prep-rooms-open offset) may assign sides and unblock
        // hasBothSides() so status can leave 'announced'.
        $format = $this->makeFormat();
        $teamA  = Team::factory()->create();
        $teamB  = Team::factory()->create();

        // scheduled 10min from now, prep offset = 0.5h → open time = 20min ago →
        // Step 2 (prep rooms) triggers, but Step 3 (start the debate) does not
        // yet — scheduled_at itself is still in the future.
        $debate = Debate::factory()->create([
            'format_id'           => $format->id,
            'status'               => 'announced',
            'scheduled_at'         => now()->addMinutes(10),
            'motion_revealed_at'   => now()->subMinutes(50),
            'proposition_team_id'  => $teamA->id,
            'opposition_team_id'   => $teamB->id,
        ]);

        $teamAUserIds = $this->makePendingTeamPool($debate, $teamA->id);
        $teamBUserIds = $this->makePendingTeamPool($debate, $teamB->id);

        $this->artisan('debates:tick');

        $debate->refresh();

        // The same two teams still occupy the two slots — only the order may have swapped.
        $this->assertEqualsCanonicalizing(
            [$teamA->id, $teamB->id],
            [$debate->proposition_team_id, $debate->opposition_team_id]
        );

        $propUserIds = $debate->proposition_team_id === $teamA->id ? $teamAUserIds : $teamBUserIds;
        $oppUserIds  = $debate->opposition_team_id === $teamA->id ? $teamAUserIds : $teamBUserIds;

        foreach ($propUserIds as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid,
                'side' => 'proposition', 'status' => 'approved',
            ]);
        }
        foreach ($oppUserIds as $uid) {
            $this->assertDatabaseHas('debate_participants', [
                'debate_id' => $debate->id, 'user_id' => $uid,
                'side' => 'opposition', 'status' => 'approved',
            ]);
        }

        $this->assertEquals('teams-selected', $debate->status);
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
