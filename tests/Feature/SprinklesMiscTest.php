<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Sprinkles §7 (live-state format superset) + §9 (contact in login). */
class SprinklesMiscTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_response_carries_support_contact(): void
    {
        config([
            'services.support.email'     => 'help@jadal.app',
            'services.support.phone'     => '+963-11-1234567',
            'services.support.instagram' => 'https://instagram.com/jadal',
        ]);

        $user = User::factory()->create([
            'status'   => 'active',
            'password' => Hash::make('secret-pass-1'),
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'secret-pass-1',
        ]);

        $res->assertStatus(200);
        $res->assertJsonPath('data.contact.email', 'help@jadal.app');
        $res->assertJsonPath('data.contact.phone', '+963-11-1234567');
        $res->assertJsonPath('data.contact.instagram', 'https://instagram.com/jadal');
    }

    public function test_live_state_format_is_a_superset_with_id_name_description_phase_config(): void
    {
        $format = DebateFormat::factory()->create([
            'name'        => 'WSDC-ish 3v3',
            'description' => 'Three speakers per side.',
            'phase_config' => [
                'speech_time_seconds'          => 300,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'motion_reveal_offset_hours'   => 0.5,
                'prep_rooms_open_offset_hours' => 0.25,
            ],
        ]);
        $debate = Debate::factory()->create(['format_id' => $format->id, 'status' => 'live']);

        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $judge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'is_attended' => false,
        ]);

        $res = $this->actingAs($judge)->getJson("/api/debates/{$debate->id}/live-state");
        $res->assertStatus(200);
        $res->assertJsonPath('data.format.id', $format->id);
        $res->assertJsonPath('data.format.name', 'WSDC-ish 3v3');
        $res->assertJsonPath('data.format.description', 'Three speakers per side.');
        // Offsets are float hours: 0.5 = 30 minutes.
        $res->assertJsonPath('data.format.phase_config.motion_reveal_offset_hours', 0.5);
        $res->assertJsonPath('data.format.motion_reveal_offset_hours', 0.5);
        $res->assertJsonPath('data.format.speech_time_seconds', 300);
    }
}
