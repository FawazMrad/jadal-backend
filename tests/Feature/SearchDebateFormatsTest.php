<?php

namespace Tests\Feature;

use App\Models\DebateFormat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchDebateFormatsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['role' => 'debater', 'status' => 'active']);
    }

    public function test_search_filters_by_name(): void
    {
        DebateFormat::factory()->create(['name' => 'Asian Parliamentary', 'description' => 'Two teams']);
        DebateFormat::factory()->create(['name' => 'World Schools',       'description' => 'Three speakers']);

        $response = $this->actingAs($this->user())->getJson('/api/debate-formats?search=Asian');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Asian Parliamentary', $response->json('data.0.name'));
    }

    public function test_search_matches_description(): void
    {
        DebateFormat::factory()->create(['name' => 'Format A', 'description' => 'uses rebuttal rounds']);
        DebateFormat::factory()->create(['name' => 'Format B', 'description' => 'no reply speeches']);

        $response = $this->actingAs($this->user())->getJson('/api/debate-formats?search=rebuttal');
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Format A', $response->json('data.0.name'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        DebateFormat::factory()->create(['name' => 'Concrete']);
        $response = $this->actingAs($this->user())->getJson('/api/debate-formats?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $response = $this->actingAs($this->user())->getJson('/api/debate-formats?search=a');
        $response->assertStatus(422);
    }
}
