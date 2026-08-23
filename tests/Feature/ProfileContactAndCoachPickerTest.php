<?php

namespace Tests\Feature;

use App\Models\ContactInfo;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Profile, contact and coach-picker behaviours that span several endpoints:
 * random-team exclusion, the derived contact links on login, the coach team
 * picker, and activity zero-filling.
 */
class ProfileContactAndCoachPickerTest extends TestCase
{
    use RefreshDatabase;

    // ── Random teams never appear on a profile ─────────────────────────────

    public function test_random_teams_are_excluded_from_both_team_lists(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $real   = Team::factory()->create(['is_random' => false, 'name' => 'Team Alpha']);
        $random = Team::factory()->create(['is_random' => true,  'name' => 'Random 7']);

        foreach ([[$real, 'current'], [$random, 'current']] as [$team, $status]) {
            TeamMember::create(['team_id' => $team->id, 'user_id' => $user->id, 'status' => $status, 'priority' => 1]);
        }

        $current = $this->actingAs($user)->getJson("/api/users/{$user->id}/teams")->assertStatus(200);
        $current->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team_name', 'Team Alpha')
            ->assertJsonPath('data.0.is_random', false);

        // Same rule on history.
        TeamMember::where('user_id', $user->id)->update(['status' => 'past']);

        $history = $this->actingAs($user)->getJson("/api/users/{$user->id}/teams/history")->assertStatus(200);
        $history->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.team_name', 'Team Alpha')
            ->assertJsonPath('data.0.is_random', false);
    }

    public function test_exclusion_also_applies_when_viewing_someone_elses_profile(): void
    {
        $subject = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $viewer  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $random  = Team::factory()->create(['is_random' => true]);

        TeamMember::create(['team_id' => $random->id, 'user_id' => $subject->id, 'status' => 'current', 'priority' => 1]);

        $this->actingAs($viewer)
            ->getJson("/api/users/{$subject->id}/teams")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // ── Linkable contact ───────────────────────────────────────────────────

    public function test_login_contact_derives_e164_and_instagram_url_without_changing_legacy_keys(): void
    {
        ContactInfo::query()->delete();
        ContactInfo::create([
            'email'     => 'help@jadal.app',
            'phone'     => '+963-11-1234567',
            'instagram' => 'https://instagram.com/jadal.platform',
            'whatsapp'  => '+962 79 000 0000',
        ]);

        $user = User::factory()->create(['role' => 'debater', 'status' => 'active', 'password' => bcrypt('secret-pass-1')]);

        $res = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertStatus(200);

        // Legacy three, byte-identical.
        $res->assertJsonPath('data.contact.email', 'help@jadal.app')
            ->assertJsonPath('data.contact.phone', '+963-11-1234567')
            ->assertJsonPath('data.contact.instagram', 'https://instagram.com/jadal.platform');

        // Derived, ready for a URI.
        $res->assertJsonPath('data.contact.phone_e164', '+963111234567')
            ->assertJsonPath('data.contact.instagram_url', 'https://instagram.com/jadal.platform')
            ->assertJsonPath('data.contact.instagram_handle', 'jadal.platform')
            ->assertJsonPath('data.contact.whatsapp', '+962790000000');

        // Unset channels are null, never empty strings.
        $res->assertJsonPath('data.contact.telegram', null)
            ->assertJsonPath('data.contact.website', null);
    }

    public function test_local_phone_yields_null_e164_rather_than_a_wrong_dial_target(): void
    {
        ContactInfo::query()->delete();
        ContactInfo::create(['phone' => '0790000000']);

        $user = User::factory()->create(['role' => 'debater', 'status' => 'active', 'password' => bcrypt('secret-pass-1')]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertStatus(200)
            ->assertJsonPath('data.contact.phone', '0790000000')
            ->assertJsonPath('data.contact.phone_e164', null);
    }

    // ── Coach team picker + single-team summary ──────────────────────────

    public function test_trainer_teams_lists_active_and_inactive_but_not_random(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $active   = Team::factory()->create(['created_by' => $coach->id, 'status' => 'active',   'is_random' => false, 'name' => 'Alpha']);
        Team::factory()->create(['created_by' => $coach->id, 'status' => 'inactive', 'is_random' => false, 'name' => 'Beta']);
        Team::factory()->create(['created_by' => $coach->id, 'is_random' => true, 'name' => 'Random 1']);

        TeamMember::create(['team_id' => $active->id, 'user_id' => User::factory()->create()->id, 'status' => 'current', 'priority' => 1]);

        $res = $this->actingAs($coach)->getJson("/api/trainers/{$coach->id}/teams")->assertStatus(200);

        $res->assertJsonCount(2, 'data')
            // Active first.
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.members_count', 1)
            ->assertJsonPath('data.0.is_random', false)
            ->assertJsonPath('data.1.name', 'Beta')
            ->assertJsonPath('data.1.is_active', false);
    }

    public function test_team_summary_narrows_to_one_team(): void
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        Team::factory()->count(3)->create(['created_by' => $coach->id, 'is_random' => false]);
        $one = Team::where('created_by', $coach->id)->first();

        $this->actingAs($coach)
            ->getJson("/api/trainers/{$coach->id}/stats/team-summary")
            ->assertStatus(200)
            ->assertJsonPath('data.teams_counted', 3);

        $this->actingAs($coach)
            ->getJson("/api/trainers/{$coach->id}/stats/team-summary?team_id={$one->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.teams_counted', 1);
    }

    public function test_team_summary_rejects_a_team_this_coach_does_not_train(): void
    {
        $coach     = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $otherTeam = Team::factory()->create(['is_random' => false]);

        $this->actingAs($coach)
            ->getJson("/api/trainers/{$coach->id}/stats/team-summary?team_id={$otherTeam->id}")
            ->assertStatus(403);

        // A non-existent id must be indistinguishable from someone else's.
        $this->actingAs($coach)
            ->getJson("/api/trainers/{$coach->id}/stats/team-summary?team_id=99999")
            ->assertStatus(403);
    }

    // ── Activity zero-fill, on every role variant ──────────────────────────

    #[DataProvider('activityRoutes')]
    public function test_activity_group_by_month_zero_fills(string $prefix, string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);

        $res = $this->actingAs($user)
            ->getJson("/api/{$prefix}/{$user->id}/stats/activity?group_by=month&from=2026-01&to=2026-03")
            ->assertStatus(200);

        $res->assertJsonPath('data.grouping', 'by_month')
            ->assertJsonCount(3, 'data.buckets')
            ->assertJsonPath('data.buckets.0.label', '2026-01')
            ->assertJsonPath('data.buckets.1.label', '2026-02')
            ->assertJsonPath('data.buckets.2.label', '2026-03')
            ->assertJsonPath('data.buckets.1.value', 0);
    }

    public static function activityRoutes(): array
    {
        return [
            'debater' => ['debaters', 'debater'],
            'trainer' => ['trainers', 'trainer'],
            'judge'   => ['judges', 'judge'],
        ];
    }
}
