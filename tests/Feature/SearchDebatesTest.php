<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\Motion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchDebatesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'trainer', 'status' => 'active']);
    }

    private function seedDebates(): void
    {
        Debate::factory()->create(['status' => 'scheduled', 'title' => 'Climate Policy Debate', 'tag' => 'env', 'scheduled_at' => now()->addDay()]);
        Debate::factory()->create(['status' => 'scheduled', 'title' => 'Economic Reform',       'tag' => 'eco', 'scheduled_at' => now()->addDays(2)]);
        Debate::factory()->create(['status' => 'scheduled', 'title' => 'Healthcare Access',     'tag' => 'health', 'scheduled_at' => now()->addDays(3)]);
    }

    public function test_no_search_returns_all_upcoming_unchanged(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->user())->getJson('/api/debates');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_search_filters_by_title(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->user())->getJson('/api/debates?search=Climate');
        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Climate Policy Debate'], $titles);
    }

    public function test_search_matches_motion_text(): void
    {
        $motion = Motion::factory()->create(['text' => 'This house supports nuclear energy']);
        $debate = Debate::factory()->create([
            'status' => 'scheduled', 'title' => 'Untitled', 'tag' => 'x',
            'motion_id' => $motion->id, 'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($this->user())->getJson('/api/debates?search=nuclear');
        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($debate->id, $ids);
    }

    public function test_nonexistent_term_returns_empty(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->user())->getJson('/api/debates?search=zzzznope');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_empty_search_is_treated_as_no_search(): void
    {
        $this->seedDebates();
        $response = $this->actingAs($this->user())->getJson('/api/debates?search=');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_single_char_search_is_rejected(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/debates?search=a');
        $response->assertStatus(422);
    }

    public function test_search_combines_with_status_filter(): void
    {
        Debate::factory()->create(['status' => 'completed', 'title' => 'Climate Archive', 'scheduled_at' => now()->subDays(2)]);
        Debate::factory()->create(['status' => 'completed', 'title' => 'Economic Archive', 'scheduled_at' => now()->subDays(2)]);
        Debate::factory()->create(['status' => 'scheduled', 'title' => 'Climate Upcoming', 'scheduled_at' => now()->addDay()]);

        $response = $this->actingAs($this->user())->getJson('/api/debates?status=completed&search=Climate');
        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertEquals(['Climate Archive'], $titles);
    }
}
