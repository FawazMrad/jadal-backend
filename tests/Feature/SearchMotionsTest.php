<?php

namespace Tests\Feature;

use App\Models\Motion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchMotionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'judge', 'status' => 'active']);
    }

    public function test_search_filters_by_text(): void
    {
        Motion::factory()->create(['text' => 'This house supports renewable energy']);
        Motion::factory()->create(['text' => 'This house would ban private cars']);

        $response = $this->actingAs($this->user())->getJson('/api/motions?search=renewable');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        Motion::factory()->count(3)->create();
        $response = $this->actingAs($this->user())->getJson('/api/motions');
        $this->assertCount(3, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        Motion::factory()->create(['text' => 'Something concrete']);
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_empty_search_not_filtered(): void
    {
        Motion::factory()->count(2)->create();
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/motions?search=a');
        $response->assertStatus(422);
    }
}
