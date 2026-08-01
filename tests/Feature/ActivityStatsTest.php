<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V2 §7 — participation/activity scoring: registration + attendance + viewing - penalty. */
class ActivityStatsTest extends TestCase
{
    use RefreshDatabase;

    private function completedDebate(array $overrides = []): Debate
    {
        return Debate::factory()->create(array_merge([
            'status' => 'completed',
            'scheduled_at' => now()->subDays(3),
            'prep_rooms_opened_at' => now()->subDays(3)->subHour(),
        ], $overrides));
    }

    public function test_registration_counts_even_when_never_selected(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debate = $this->completedDebate();
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $debater->id, 'team_id' => null,
            'role' => 'debater', 'side' => null, 'status' => 'rejected', 'is_chair' => false, 'is_attended' => false,
        ]);

        $res = $this->actingAs($debater)->getJson("/api/debaters/{$debater->id}/stats/activity");
        $res->assertStatus(200);
        $res->assertJsonPath('data.totals.breakdown.registration.count', 1);
        // Rejected (never selected) → no attendance, no penalty entries at all.
        $res->assertJsonPath('data.totals.breakdown.attendance.count', 0);
        $res->assertJsonPath('data.totals.breakdown.penalty.count', 0);
        $this->assertGreaterThan(0, $res->json('data.totals.value'));
    }

    public function test_selected_but_absent_debater_is_penalized_not_missing(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debate = $this->completedDebate();
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $debater->id, 'team_id' => null,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
            'is_chair' => false, 'is_attended' => false, 'prep_attended_at' => null,
        ]);

        $res = $this->actingAs($debater)->getJson("/api/debaters/{$debater->id}/stats/activity");
        $res->assertJsonPath('data.totals.breakdown.penalty.count', 1);
        $res->assertJsonPath('data.totals.breakdown.attendance.count', 0);
        $this->assertLessThan(0, $res->json('data.totals.breakdown.penalty.points'));
    }

    public function test_judge_attendance_weighted_heavier_than_debater(): void
    {
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $judgeDebate = $this->completedDebate();
        DebateParticipant::create([
            'debate_id' => $judgeDebate->id, 'user_id' => $judge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'is_attended' => false, 'first_attended_at' => now()->subDays(3),
        ]);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debaterDebate = $this->completedDebate();
        DebateParticipant::create([
            'debate_id' => $debaterDebate->id, 'user_id' => $debater->id, 'team_id' => null,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
            'is_chair' => false, 'is_attended' => false, 'prep_attended_at' => now()->subDays(3),
        ]);

        $judgeRes = $this->actingAs($judge)->getJson("/api/judges/{$judge->id}/stats/activity");
        $debaterRes = $this->actingAs($debater)->getJson("/api/debaters/{$debater->id}/stats/activity");

        $judgeAttendancePoints = $judgeRes->json('data.totals.breakdown.attendance.points');
        $debaterAttendancePoints = $debaterRes->json('data.totals.breakdown.attendance.points');

        $this->assertGreaterThan($debaterAttendancePoints, $judgeAttendancePoints);
    }

    public function test_viewing_counted_for_non_participant(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debate = $this->completedDebate();
        DebateViewer::create(['debate_id' => $debate->id, 'user_id' => $viewer->id, 'viewed_at' => now()->subDays(3)]);

        $res = $this->actingAs($viewer)->getJson("/api/debaters/{$viewer->id}/stats/activity");
        $res->assertJsonPath('data.totals.breakdown.viewing.count', 1);
        $this->assertGreaterThan(0, $res->json('data.totals.breakdown.viewing.points'));
    }

    public function test_date_range_filters_events(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $inRange = $this->completedDebate(['scheduled_at' => '2025-03-01']);
        $outOfRange = $this->completedDebate(['scheduled_at' => '2025-08-01']);
        DebateViewer::create(['debate_id' => $inRange->id, 'user_id' => $viewer->id, 'viewed_at' => now()]);
        DebateViewer::create(['debate_id' => $outOfRange->id, 'user_id' => $viewer->id, 'viewed_at' => now()]);

        $res = $this->actingAs($viewer)->getJson("/api/debaters/{$viewer->id}/stats/activity?from=2025-02&to=2025-04");
        $res->assertJsonPath('data.totals.breakdown.viewing.count', 1);
    }

    public function test_viewer_joining_main_room_via_webhook_is_recorded(): void
    {
        $debate = Debate::factory()->create(['status' => 'live', 'livekit_room_name' => 'debate-view-main']);
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->postJson('/api/livekit/webhook', [
            'event' => 'participant_joined',
            'room' => ['name' => 'debate-view-main'],
            'participant' => ['identity' => (string) $viewer->id],
        ])->assertStatus(200);

        $this->assertDatabaseHas('debate_viewers', ['debate_id' => $debate->id, 'user_id' => $viewer->id]);
    }

    /**
     * Frontend spec §6.4 — statistics are public for every user. The
     * stats_visible opt-out is gone, so a stranger reads the same 200 the
     * owner does. (This test previously asserted the opposite.)
     */
    public function test_activity_is_public_to_any_authenticated_user(): void
    {
        $target = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($stranger)->getJson("/api/debaters/{$target->id}/stats/activity")->assertStatus(200);
        $this->actingAs($target)->getJson("/api/debaters/{$target->id}/stats/activity")->assertStatus(200);
    }
}
