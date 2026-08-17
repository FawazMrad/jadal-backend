<?php

namespace Tests\Feature;

use App\Models\DebateFormat;
use App\Models\Motion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the `phase_config` shape contract and the motion ordering fix.
 *
 * The bug these cover: `phase_config` is one JSON column that an update REPLACES
 * wholesale. Because every inner rule carried `sometimes`, a payload sending the
 * legacy array-of-phases shape skipped all of them, validated, and overwrote the
 * column with a shape `DebateFormat::deriveStages()` cannot read — after which
 * debates on that format can never start.
 */
class PhaseConfigIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CONFIG = [
        'speech_time_seconds'          => 480,
        'has_reply_speech'             => true,
        'reply_time_seconds'           => 240,
        'protected_time_seconds'       => 60,
        'motion_reveal_offset_hours'   => 24,
        'prep_rooms_open_offset_hours' => 1,
    ];

    /** The shape some production rows still hold, and that the dashboard echoes back. */
    private const LEGACY_LIST = [
        ['name' => 'Prime Minister',       'duration_seconds' => 420, 'role' => 'proposition', 'order_index' => 1],
        ['name' => 'Leader of Opposition', 'duration_seconds' => 420, 'role' => 'opposition',  'order_index' => 2],
        ['name' => 'Opposition Reply',     'duration_seconds' => 240, 'role' => 'opposition',  'order_index' => 3, 'is_reply' => true],
    ];

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function format(array $config = self::VALID_CONFIG): DebateFormat
    {
        return DebateFormat::factory()->create(['phase_config' => $config]);
    }

    // ── The vulnerability ─────────────────────────────────────────────────────

    public function test_update_rejects_the_legacy_array_of_phases(): void
    {
        $format = $this->format();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", [
                'phase_config' => self::LEGACY_LIST,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phase_config');

        // The critical assertion: the column is UNCHANGED. Previously this
        // payload was accepted and silently destroyed the timings.
        $this->assertSame(self::VALID_CONFIG, $format->fresh()->phase_config);
    }

    public function test_update_rejects_a_partial_timing_object(): void
    {
        // Sending phase_config at all replaces it wholesale, so a half-object
        // would silently drop the missing keys.
        $format = $this->format();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", [
                'phase_config' => ['speech_time_seconds' => 300],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'phase_config.has_reply_speech',
                'phase_config.motion_reveal_offset_hours',
                'phase_config.prep_rooms_open_offset_hours',
            ]);

        $this->assertSame(self::VALID_CONFIG, $format->fresh()->phase_config);
    }

    public function test_update_accepts_a_complete_timing_object(): void
    {
        $format = $this->format();

        $new = [
            'speech_time_seconds'          => 300,
            'has_reply_speech'             => false,
            'reply_time_seconds'           => 0,
            'protected_time_seconds'       => 45,
            'motion_reveal_offset_hours'   => 0.5,
            'prep_rooms_open_offset_hours' => 0.5,
        ];

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", ['phase_config' => $new])
            ->assertStatus(200);

        $this->assertSame($new, $format->fresh()->phase_config);
    }

    public function test_partial_update_without_phase_config_still_works(): void
    {
        // Tightening the rules must not break name/description-only edits.
        $format = $this->format();

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", ['name' => 'Renamed Format'])
            ->assertStatus(200);

        $fresh = $format->fresh();
        $this->assertSame('Renamed Format', $fresh->name);
        $this->assertSame(self::VALID_CONFIG, $fresh->phase_config);
    }

    public function test_create_also_rejects_the_legacy_shape(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/debate-formats', [
                'name'         => 'Legacy Shaped',
                'phase_config' => self::LEGACY_LIST,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('debate_formats', ['name' => 'Legacy Shaped']);
    }

    // ── protected_time_seconds (the "reserved time" field) ────────────────────

    public function test_protected_time_is_accepted_and_persisted(): void
    {
        $format = $this->format();

        $new = self::VALID_CONFIG;
        $new['protected_time_seconds'] = 90;

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", ['phase_config' => $new])
            ->assertStatus(200);

        $this->assertSame(90, $format->fresh()->phase_config['protected_time_seconds']);
    }

    public function test_protected_time_accepts_zero_meaning_no_protected_window(): void
    {
        $format = $this->format();

        $new = self::VALID_CONFIG;
        $new['protected_time_seconds'] = 0;

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", ['phase_config' => $new])
            ->assertStatus(200);

        // 0 is meaningful ("POIs allowed throughout") and must survive the
        // default-filling in prepareForValidation().
        $this->assertSame(0, $format->fresh()->phase_config['protected_time_seconds']);
    }

    public function test_omitting_protected_time_defaults_it_instead_of_failing(): void
    {
        // A client that has not been updated yet still sends the old five keys.
        // It must keep working, and the column must still end up complete.
        $format = $this->format();

        $legacyPayload = self::VALID_CONFIG;
        unset($legacyPayload['protected_time_seconds']);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/debate-formats/{$format->id}", ['phase_config' => $legacyPayload])
            ->assertStatus(200);

        $this->assertSame(60, $format->fresh()->phase_config['protected_time_seconds']);
    }

    public function test_protected_time_is_exposed_on_live_state_format(): void
    {
        $format = $this->format();
        $debate = \App\Models\Debate::factory()->create([
            'format_id' => $format->id,
            'status'    => 'live',
        ]);

        $this->actingAs($this->admin())
            ->getJson("/api/debates/{$debate->id}/live-state")
            ->assertStatus(200)
            ->assertJsonPath('data.format.protected_time_seconds', 60);
    }

    public function test_normalize_backfills_protected_time_on_an_otherwise_healthy_row(): void
    {
        // The field is newer than the other four, so rows written before it
        // exists are "incomplete" and must be topped up without disturbing
        // their existing timings.
        $config = self::VALID_CONFIG;
        unset($config['protected_time_seconds']);
        $format = $this->format($config);

        $this->artisan('debate-formats:normalize --force')->assertSuccessful();

        $fresh = $format->fresh()->phase_config;

        $this->assertSame(60, $fresh['protected_time_seconds']);
        // Existing values untouched — the backfill must not reset anything.
        $this->assertSame(480, $fresh['speech_time_seconds']);
        $this->assertTrue($fresh['has_reply_speech']);
        $this->assertSame(240, $fresh['reply_time_seconds']);
    }

    // ── The repair command ────────────────────────────────────────────────────

    public function test_normalize_command_dry_run_changes_nothing(): void
    {
        $broken = $this->format(self::LEGACY_LIST);

        $this->artisan('debate-formats:normalize')->assertSuccessful();

        $this->assertSame(self::LEGACY_LIST, $broken->fresh()->phase_config);
    }

    public function test_normalize_command_repairs_a_legacy_row_with_force(): void
    {
        $broken = $this->format(self::LEGACY_LIST);

        $this->artisan('debate-formats:normalize --force')->assertSuccessful();

        $config = $broken->fresh()->phase_config;

        // Derived from the phases: 420 is the modal main duration, the reply
        // phase implies has_reply_speech, and 240 is the reply duration.
        $this->assertSame(420, $config['speech_time_seconds']);
        $this->assertTrue($config['has_reply_speech']);
        $this->assertSame(240, $config['reply_time_seconds']);
        // Not derivable from the legacy shape — filled from the option defaults.
        $this->assertSame(24.0, (float) $config['motion_reveal_offset_hours']);
        $this->assertSame(1.0, (float) $config['prep_rooms_open_offset_hours']);
    }

    public function test_normalize_command_leaves_healthy_rows_alone(): void
    {
        $healthy = $this->format();

        $this->artisan('debate-formats:normalize --force')->assertSuccessful();

        $this->assertSame(self::VALID_CONFIG, $healthy->fresh()->phase_config);
    }

    public function test_repaired_format_can_generate_stages(): void
    {
        // The whole point: a repaired format must survive deriveStages(), which
        // is what AdvanceDebatesLifecycle calls to take a debate live.
        $broken = $this->format(self::LEGACY_LIST);

        $this->artisan('debate-formats:normalize --force')->assertSuccessful();

        $stages = $broken->fresh()->deriveStages();

        $this->assertCount(8, $stages); // 3 speakers × 2 sides + 2 replies
        foreach ($stages as $stage) {
            $this->assertNotNull(
                $stage['duration_seconds'],
                'a null duration is what breaks the NOT NULL debate_phases insert'
            );
        }
    }

    // ── Motion ordering ───────────────────────────────────────────────────────

    public function test_motions_are_returned_newest_first(): void
    {
        $oldest = Motion::factory()->create(['text' => 'This house was created first']);
        $newest = Motion::factory()->create(['text' => 'This house was created last']);

        $ids = collect(
            $this->actingAs($this->admin())->getJson('/api/motions')->json('data')
        )->pluck('id')->all();

        // Previously there was no ORDER BY at all, so a newly created motion
        // sorted last and fell off page 1 entirely.
        $this->assertSame([$newest->id, $oldest->id], $ids);
    }

    public function test_newly_created_motion_appears_on_the_first_page(): void
    {
        Motion::factory()->count(25)->create();
        $newest = Motion::factory()->create(['text' => 'Brand new motion']);

        $ids = collect(
            $this->actingAs($this->admin())->getJson('/api/motions')->json('data')
        )->pluck('id')->all();

        $this->assertContains($newest->id, $ids);
        $this->assertSame($newest->id, $ids[0]);
    }

    public function test_per_page_is_honoured_and_capped_without_rejecting(): void
    {
        Motion::factory()->count(60)->create();
        $admin = $this->admin();

        // Default
        $this->assertCount(20, $this->actingAs($admin)->getJson('/api/motions')->json('data'));

        // Honoured
        $this->assertCount(5, $this->actingAs($admin)->getJson('/api/motions?per_page=5')->json('data'));

        // Clamped, NOT rejected — existing callers send per_page=1000 and must
        // keep working rather than start getting 422s.
        $response = $this->actingAs($admin)->getJson('/api/motions?per_page=1000');
        $response->assertStatus(200);
        $this->assertCount(50, $response->json('data'));
        $this->assertSame(50, $response->json('meta.per_page'));
    }
}
