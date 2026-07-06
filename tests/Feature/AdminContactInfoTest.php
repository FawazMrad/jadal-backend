<?php

namespace Tests\Feature;

use App\Models\ContactInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V2 — admin-editable support contact. */
class AdminContactInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_contact_info(): void
    {
        ContactInfo::create(['email' => 'old@jadal.app', 'phone' => null, 'instagram' => null]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->getJson('/api/admin/contact-info')
            ->assertStatus(200)
            ->assertJsonPath('data.email', 'old@jadal.app');

        $update = $this->actingAs($admin)->putJson('/api/admin/contact-info', [
            'email' => 'new@jadal.app', 'phone' => '+963111', 'instagram' => 'https://instagram.com/jadal',
        ]);
        $update->assertStatus(200);
        $update->assertJsonPath('data.email', 'new@jadal.app');

        $this->assertDatabaseHas('contact_infos', ['email' => 'new@jadal.app', 'phone' => '+963111']);
    }

    public function test_non_admin_cannot_update_contact_info(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($user)->putJson('/api/admin/contact-info', ['email' => 'x@x.com'])->assertStatus(403);
    }

    public function test_updated_contact_info_is_returned_on_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => bcrypt('secret-pass-1')]);
        $this->actingAs($admin)->putJson('/api/admin/contact-info', [
            'email' => 'support2@jadal.app',
        ])->assertStatus(200);

        $res = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret-pass-1']);
        $res->assertJsonPath('data.contact.email', 'support2@jadal.app');
    }
}
