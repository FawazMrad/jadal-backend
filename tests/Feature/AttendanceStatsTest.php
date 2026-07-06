<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprinkles §6.5 — attendance stats (debater prep / coach / judge) + the
 * webhook stamping that feeds them.
 */
class AttendanceStatsTest extends TestCase
{
    use RefreshDatabase;

    private function completedDebate(array $overrides = []): Debate
    {
        return Debate::factory()->create(array_merge([
            'status'               => 'completed',
            'scheduled_at'         => now()->subDays(3),
            'prep_rooms_opened_at' => now()->subDays(3)->subHour(),
        ], $overrides));
    }

    private function participant(Debate $debate, User $user, string $role, array $overrides = []): DebateParticipant
    {
        return DebateParticipant::create(array_merge([
            'debate_id' => $debate->id, 'user_id' => $user->id, 'team_id' => null,
            'role' => $role, 'side' => $role === 'debater' ? 'proposition' : $role,
            'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ], $overrides));
    }

    public function test_judge_attendance_rate_counts_only_selected_debates(): void
    {
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        // Selected + attended.
        $this->participant($this->completedDebate(), $judge, 'judge', ['first_attended_at' => now()->subDays(3)]);
        // Selected + missed.
        $this->participant($this->completedDebate(), $judge, 'judge');
        // Registered but NEVER selected (rejected) — must not hurt the rate.
        $this->participant($this->completedDebate(), $judge, 'judge', ['status' => 'rejected']);

        $res = $this->actingAs($judge)->getJson("/api/judges/{$judge->id}/stats/attendance");
        $res->assertStatus(200);
        $res->assertJsonPath('data.stat', 'attendance');
        $res->assertJsonPath('data.totals.registered', 3);
        $res->assertJsonPath('data.totals.selected', 2);
        $res->assertJsonPath('data.totals.attended', 1);
        $res->assertJsonPath('data.totals.rate', 0.5); // 1 ÷ 2, rejected excluded
    }

    public function test_debater_prep_attendance_uses_prep_stamp_and_requires_prep_to_have_opened(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        // Prep opened + attended.
        $this->participant($this->completedDebate(), $debater, 'debater', ['prep_attended_at' => now()->subDays(3)]);
        // Prep opened + missed.
        $this->participant($this->completedDebate(), $debater, 'debater');
        // Prep NEVER opened — cannot be missed, excluded entirely.
        $this->participant($this->completedDebate(['prep_rooms_opened_at' => null]), $debater, 'debater');

        $res = $this->actingAs($debater)->getJson("/api/debaters/{$debater->id}/stats/prep-attendance");
        $res->assertStatus(200);
        $res->assertJsonPath('data.stat', 'prep_attendance');
        $res->assertJsonPath('data.totals.selected', 2);
        $res->assertJsonPath('data.totals.attended', 1);
        $res->assertJsonPath('data.totals.rate', 0.5);
    }

    public function test_trainer_attendance_endpoint_works_and_is_gated(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->participant($this->completedDebate(), $coach, 'trainer', [
            'side' => 'trainer', 'first_attended_at' => now()->subDays(3),
        ]);

        $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/stats/attendance")
            ->assertStatus(200)
            ->assertJsonPath('data.totals.rate', 1);

        // V2 §9 — stats are public by default: a stranger CAN view them...
        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($stranger)->getJson("/api/trainers/{$coach->id}/stats/attendance")
            ->assertStatus(200);

        // ...unless the coach opted out.
        $coach->update(['stats_visible' => false]);
        $this->actingAs($stranger)->getJson("/api/trainers/{$coach->id}/stats/attendance")
            ->assertStatus(403);
        $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/stats/attendance")
            ->assertStatus(200);
    }

    public function test_webhook_join_stamps_sticky_attendance_and_leave_does_not_clear_it(): void
    {
        $debate = Debate::factory()->create([
            'status'            => 'live',
            'livekit_room_name' => 'debate-w-main',
            'prop_room_name'    => 'debate-w-prop',
            'opp_room_name'     => 'debate-w-opp',
            'result_room_name'  => 'debate-w-result',
        ]);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $p = $this->participant($debate, $debater, 'debater');

        // Join the PREP room → prep stamp only.
        $this->postJson('/api/livekit/webhook', [
            'event'       => 'participant_joined',
            'room'        => ['name' => 'debate-w-prop'],
            'participant' => ['identity' => (string) $debater->id],
        ])->assertStatus(200);

        $p->refresh();
        $this->assertNotNull($p->prep_attended_at);
        $this->assertNull($p->first_attended_at);

        // Join the MAIN room → main stamp.
        $this->postJson('/api/livekit/webhook', [
            'event'       => 'participant_joined',
            'room'        => ['name' => 'debate-w-main'],
            'participant' => ['identity' => (string) $debater->id],
        ])->assertStatus(200);

        $p->refresh();
        $this->assertNotNull($p->first_attended_at);
        $firstStamp = $p->first_attended_at;

        // Leaving clears the LIVE flag but never the sticky stamps.
        $this->postJson('/api/livekit/webhook', [
            'event'       => 'participant_left',
            'room'        => ['name' => 'debate-w-main'],
            'participant' => ['identity' => (string) $debater->id],
        ])->assertStatus(200);

        $p->refresh();
        $this->assertFalse((bool) $p->is_attended);
        $this->assertNotNull($p->first_attended_at);
        $this->assertEquals($firstStamp, $p->first_attended_at);
    }
}
