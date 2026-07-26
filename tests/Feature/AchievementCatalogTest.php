<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Achievement feature redesign — catalog CRUD + the delete-guard + available-for-user. */
class AchievementCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_show_and_update_an_achievement(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $create = $this->actingAs($admin)->postJson('/api/admin/achievements', [
            'name' => 'Best Speaker', 'type' => 'GOLD',
        ]);
        $create->assertStatus(201);
        $create->assertJsonPath('data.name', 'Best Speaker');
        $create->assertJsonPath('data.type', 'GOLD');
        $create->assertJsonPath('data.assigned_count', 0);
        $id = $create->json('data.id');

        $index = $this->actingAs($admin)->getJson('/api/admin/achievements');
        $index->assertStatus(200);
        $this->assertTrue(collect($index->json('data'))->contains(fn ($a) => $a['id'] === $id));

        $show = $this->actingAs($admin)->getJson("/api/admin/achievements/{$id}");
        $show->assertStatus(200)->assertJsonPath('data.id', $id);

        $update = $this->actingAs($admin)->putJson("/api/admin/achievements/{$id}", [
            'name' => 'Best Speaker — Updated', 'type' => 'SILVER',
        ]);
        $update->assertStatus(200);
        $update->assertJsonPath('data.name', 'Best Speaker — Updated');
        $update->assertJsonPath('data.type', 'SILVER');
    }

    public function test_create_rejects_invalid_type_and_missing_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)->postJson('/api/admin/achievements', [
            'name' => 'X', 'type' => 'PLATINUM',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/admin/achievements', [
            'type' => 'GOLD',
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_manage_the_catalog(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $achievement = Achievement::create(['name' => 'X', 'type' => 'GOLD']);

        $this->actingAs($debater)->postJson('/api/admin/achievements', ['name' => 'X', 'type' => 'GOLD'])
            ->assertStatus(403);
        $this->actingAs($debater)->putJson("/api/admin/achievements/{$achievement->id}", ['name' => 'Y'])
            ->assertStatus(403);
        $this->actingAs($debater)->deleteJson("/api/admin/achievements/{$achievement->id}")
            ->assertStatus(403);
        $this->actingAs($debater)->getJson('/api/admin/achievements')
            ->assertStatus(403);
    }

    public function test_delete_is_blocked_while_assigned_unless_forced(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $achievement = Achievement::create(['name' => 'X', 'type' => 'GOLD']);

        $user->achievements()->attach($achievement->id, ['assigned_at' => now(), 'assigned_by' => $admin->id]);

        $blocked = $this->actingAs($admin)->deleteJson("/api/admin/achievements/{$achievement->id}");
        $blocked->assertStatus(409);
        $blocked->assertJsonPath('errors.assigned_count', 1);
        $this->assertDatabaseHas('achievements', ['id' => $achievement->id]);

        $forced = $this->actingAs($admin)->deleteJson("/api/admin/achievements/{$achievement->id}?force=true");
        $forced->assertStatus(200);
        $this->assertDatabaseMissing('achievements', ['id' => $achievement->id]);
        $this->assertDatabaseMissing('achievement_assignments', ['achievement_id' => $achievement->id]);
    }

    public function test_available_excludes_achievements_the_user_already_has(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $user  = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $has    = Achievement::create(['name' => 'Has', 'type' => 'GOLD']);
        $doesnt = Achievement::create(['name' => 'Missing', 'type' => 'SILVER']);

        $user->achievements()->attach($has->id, ['assigned_at' => now(), 'assigned_by' => $admin->id]);

        $res = $this->actingAs($admin)->getJson("/api/admin/users/{$user->id}/achievements/available");
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($doesnt->id, $ids);
        $this->assertNotContains($has->id, $ids);
    }
}
