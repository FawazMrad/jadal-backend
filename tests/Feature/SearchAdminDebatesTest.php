<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchAdminDebatesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function seedDebates(): void
    {
        Debate::factory()->create(['status' => 'live',      'title' => 'Climate Policy',  'tag' => 'env']);
        Debate::factory()->create(['status' => 'completed', 'title' => 'Economic Reform', 'tag' => 'eco']);
        Debate::factory()->create(['status' => 'scheduled', 'title' => 'Healthcare',      'tag' => 'health']);
    }

    public function test_no_search_returns_all(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->admin())->getJson('/api/admin/debates');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_search_filters_by_title(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->admin())->getJson('/api/admin/debates?search=Climate');
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Climate Policy', $response->json('data.0.title'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->admin())->getJson('/api/admin/debates?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/admin/debates?search=a');
        $response->assertStatus(422);
    }

    public function test_search_combines_with_status_filter(): void
    {
        Debate::factory()->create(['status' => 'completed', 'title' => 'Climate Old']);
        Debate::factory()->create(['status' => 'live',      'title' => 'Climate New']);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/debates?status=completed&search=Climate');
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Climate Old'], $titles);
    }
}
