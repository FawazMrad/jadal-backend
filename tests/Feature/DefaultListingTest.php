<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_filter_returns_only_upcoming_and_live_statuses(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        Debate::factory()->create(['status' => 'scheduled',      'scheduled_at' => now()->addDay()]);
        Debate::factory()->create(['status' => 'announced',      'scheduled_at' => now()->addDays(2)]);
        Debate::factory()->create(['status' => 'teams-selected', 'scheduled_at' => now()->addDays(3)]);
        Debate::factory()->create(['status' => 'live',           'scheduled_at' => now()->subMinutes(10)]);
        // Excluded by default:
        Debate::factory()->create(['status' => 'completed',      'scheduled_at' => now()->subDays(5)]);
        Debate::factory()->create(['status' => 'cancelled',      'scheduled_at' => now()->subDays(3)]);

        $response = $this->actingAs($user)->getJson('/api/debates');

        $response->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();

        $this->assertCount(4, $response->json('data'));
        $this->assertEqualsCanonicalizing(
            ['scheduled', 'announced', 'teams-selected', 'live'],
            $statuses
        );
    }

    public function test_default_sort_is_scheduled_descending(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $later   = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(10)]);
        $sooner  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(1)]);

        $response = $this->actingAs($user)->getJson('/api/debates');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$later->id, $sooner->id], $ids);
    }

    /** The old default is still reachable, so existing clients are not stranded. */
    public function test_ascending_sort_is_still_available_explicitly(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $later  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(10)]);
        $sooner = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(1)]);

        $response = $this->actingAs($user)->getJson('/api/debates?sort=scheduled_asc');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$sooner->id, $later->id], $ids);
    }

    /** Rows sharing a scheduled_at must not reshuffle between paginated requests. */
    public function test_ordering_is_deterministic_for_identical_timestamps(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $at = now()->addDays(2);
        $a  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => $at]);
        $b  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => $at]);
        $c  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => $at]);

        $ids = collect($this->actingAs($user)->getJson('/api/debates')->json('data'))
            ->pluck('id')->all();

        $this->assertEquals([$c->id, $b->id, $a->id], $ids);
    }

    public function test_per_page_is_capped_at_50(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $response = $this->actingAs($user)->getJson('/api/debates?per_page=51');

        $response->assertStatus(422);
    }
}
