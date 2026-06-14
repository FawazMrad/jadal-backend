<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedOnePerStatus(): void
    {
        foreach (['scheduled', 'announced', 'teams-selected', 'live', 'completed', 'cancelled'] as $i => $status) {
            Debate::factory()->create([
                'status'       => $status,
                'scheduled_at' => now()->addDays($i + 1),
            ]);
        }
    }

    public function test_status_completed_returns_only_archive(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->seedOnePerStatus();

        $response = $this->actingAs($user)->getJson('/api/debates?status=completed');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertEquals(['completed'], $statuses);
    }

    public function test_status_cancelled_returns_only_cancelled(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->seedOnePerStatus();

        $response = $this->actingAs($user)->getJson('/api/debates?status=cancelled');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertEquals(['cancelled'], $statuses);
    }

    public function test_comma_separated_statuses_are_supported(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $this->seedOnePerStatus();

        $response = $this->actingAs($user)->getJson('/api/debates?status=scheduled,announced');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['scheduled', 'announced'], $statuses);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/debates?status=bogus');

        $response->assertStatus(422);
    }
}
