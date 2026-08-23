<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Notifications\PushType;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Device registry, payload contract and localized copy. */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ── device registration ─────────────────────────────────────────────

    public function test_register_device_stores_token_platform_and_locale(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/devices', [
            'token'    => 'tok-abc',
            'platform' => 'android',
            'locale'   => 'ar',
        ])->assertStatus(200);

        $this->assertDatabaseHas('devices', [
            'user_id' => $user->id, 'token' => 'tok-abc', 'platform' => 'android', 'locale' => 'ar',
        ]);
    }

    /** Re-registering the same token must not create duplicates (upsert). */
    public function test_registering_same_token_twice_does_not_duplicate(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        foreach (['ar', 'en'] as $locale) {
            $this->actingAs($user)->postJson('/api/devices', [
                'token' => 'tok-same', 'platform' => 'ios', 'locale' => $locale,
            ])->assertStatus(200);
        }

        $this->assertDatabaseCount('devices', 1);
        // The latest registration wins, which is how a language switch updates.
        $this->assertDatabaseHas('devices', ['token' => 'tok-same', 'locale' => 'en']);
    }

    /**
     * A physical device handed to another user must be RE-ASSIGNED, not
     * duplicated — otherwise user A's pushes keep landing on a phone now used
     * by user B.
     */
    public function test_token_is_reassigned_when_a_different_user_registers_it(): void
    {
        $first  = User::factory()->create(['status' => 'active']);
        $second = User::factory()->create(['status' => 'active']);

        $this->actingAs($first)->postJson('/api/devices', [
            'token' => 'tok-shared', 'platform' => 'android',
        ])->assertStatus(200);

        $this->actingAs($second)->postJson('/api/devices', [
            'token' => 'tok-shared', 'platform' => 'android',
        ])->assertStatus(200);

        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseHas('devices', ['token' => 'tok-shared', 'user_id' => $second->id]);
    }

    public function test_unregister_is_idempotent_for_unknown_tokens(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->deleteJson('/api/devices', ['token' => 'never-seen'])
            ->assertStatus(200);
    }

    public function test_unregister_removes_the_device(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Device::create(['user_id' => $user->id, 'token' => 'tok-bye', 'platform' => 'ios', 'locale' => 'ar']);

        $this->actingAs($user)->deleteJson('/api/devices', ['token' => 'tok-bye'])->assertStatus(200);

        $this->assertDatabaseMissing('devices', ['token' => 'tok-bye']);
    }

    public function test_registration_validates_platform(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->postJson('/api/devices', [
            'token' => 'tok-x', 'platform' => 'windows-phone',
        ])->assertStatus(422);
    }

    // ──/ payload + localization contract ──────────────────────────

    public function test_all_eight_types_exist_with_both_locales_and_a_deep_link(): void
    {
        $this->assertCount(8, PushType::all());

        foreach (PushType::all() as $type) {
            $this->assertNotNull(PushType::deepLink($type), "{$type} needs a deep link");

            foreach (['ar', 'en'] as $locale) {
                $copy = PushType::copy($type, $locale);
                $this->assertNotSame('', $copy['title'], "{$type}/{$locale} title");
                $this->assertNotSame('', $copy['body'], "{$type}/{$locale} body");
            }
        }
    }

    public function test_copy_is_localized_and_substitutes_placeholders(): void
    {
        $ar = PushType::copy(PushType::DEBATE_ACCEPTED, 'ar', ['debate_title' => 'مناظرة الاقتصاد']);
        $en = PushType::copy(PushType::DEBATE_ACCEPTED, 'en', ['debate_title' => 'Economy debate']);

        $this->assertStringContainsString('مناظرة الاقتصاد', $ar['body']);
        $this->assertStringContainsString('Economy debate', $en['body']);
        $this->assertNotSame($ar['title'], $en['title']);

        // No unsubstituted placeholders left behind.
        $this->assertStringNotContainsString(':debate_title', $en['body']);
    }

    /** An unknown locale must fall back to English rather than emitting blanks. */
    public function test_unknown_locale_falls_back_to_english(): void
    {
        $fallback = PushType::copy(PushType::MOTION_REVEALED, 'fr');
        $english  = PushType::copy(PushType::MOTION_REVEALED, 'en');

        $this->assertSame($english['title'], $fallback['title']);
    }

    // ── delivery safety ────────────────────────────────────────────────────────

    /**
     * Push must be a no-op when FCM is unconfigured — a missing credential can
     * never be allowed to break the debate/team action that triggered it.
     */
    public function test_send_is_a_safe_noop_when_fcm_is_not_configured(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.credentials' => null]);
        Http::fake();

        $user = User::factory()->create(['status' => 'active']);
        Device::create(['user_id' => $user->id, 'token' => 'tok-1', 'platform' => 'android', 'locale' => 'ar']);

        app(PushService::class)->sendToUsers([$user->id], PushType::DEBATE_CREATED, ['debate_id' => 1]);

        Http::assertNothingSent();
    }

    public function test_sending_to_users_without_devices_does_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create(['status' => 'active']);

        app(PushService::class)->sendToUsers([$user->id], PushType::DEBATE_CREATED, ['debate_id' => 1]);

        Http::assertNothingSent();
    }
}
