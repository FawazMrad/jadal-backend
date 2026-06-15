<?php

namespace Tests\Feature;

use App\Models\MotionFramework;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchMotionFrameworksTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'debater', 'status' => 'active']);
    }

    public function test_search_filters_by_name(): void
    {
        MotionFramework::factory()->create(['name' => 'Ethical']);
        MotionFramework::factory()->create(['name' => 'Political']);

        $response = $this->actingAs($this->user())->getJson('/api/motion-frameworks?search=Ethic');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Ethical', $response->json('data.0.name'));
    }

    public function test_no_search_returns_all(): void
    {
        MotionFramework::factory()->count(4)->create();
        $response = $this->actingAs($this->user())->getJson('/api/motion-frameworks');
        $this->assertCount(4, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        MotionFramework::factory()->create(['name' => 'Social']);
        $response = $this->actingAs($this->user())->getJson('/api/motion-frameworks?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/motion-frameworks?search=a');
        $response->assertStatus(422);
    }
}
