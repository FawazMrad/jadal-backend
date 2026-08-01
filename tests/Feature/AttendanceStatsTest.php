<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Frontend spec §1.6 — the attendance feature is removed from the app.
 *
 * The three endpoints stay ROUTED for one release and return 410 Gone, so an
 * un-updated client gets an unambiguous "this was removed" rather than a 404
 * that looks like a broken deploy. The controller and service behind them are
 * deleted. This test pins that contract until the routes themselves go.
 *
 * The activity endpoints are a DIFFERENT feature and are explicitly kept — the
 * last test here guards against them being removed by association.
 */
class AttendanceStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_debater_prep_attendance_is_gone(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson("/api/debaters/{$user->id}/stats/prep-attendance")
            ->assertStatus(410)
            ->assertJsonPath('success', false);
    }

    public function test_trainer_attendance_is_gone(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $this->actingAs($trainer)
            ->getJson("/api/trainers/{$trainer->id}/stats/attendance")
            ->assertStatus(410);
    }

    public function test_judge_attendance_is_gone(): void
    {
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($judge)
            ->getJson("/api/judges/{$judge->id}/stats/attendance")
            ->assertStatus(410);
    }

    /** Activity is a separate feature and must survive the attendance removal. */
    public function test_activity_endpoints_are_untouched(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $judge   = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($debater)
            ->getJson("/api/debaters/{$debater->id}/stats/activity")
            ->assertStatus(200);

        $this->actingAs($judge)
            ->getJson("/api/judges/{$judge->id}/stats/activity")
            ->assertStatus(200);
    }
}
