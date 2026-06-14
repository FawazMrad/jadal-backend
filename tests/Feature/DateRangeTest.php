<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_and_to_date_filter_by_scheduled_at(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $inRange  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)]);
        $tooLate  = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->addDays(10)]);
        $tooEarly = Debate::factory()->create(['status' => 'scheduled', 'scheduled_at' => now()->subDays(2)]);

        $from = now()->addDay()->toDateString();
        $to   = now()->addDays(5)->toDateString();

        $response = $this->actingAs($user)->getJson("/api/debates?from_date={$from}&to_date={$to}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($inRange->id, $ids);
        $this->assertNotContains($tooLate->id, $ids);
        $this->assertNotContains($tooEarly->id, $ids);
    }

    public function test_inverted_range_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $from = now()->addDays(5)->toDateString();
        $to   = now()->addDay()->toDateString(); // before from_date

        $response = $this->actingAs($user)->getJson("/api/debates?from_date={$from}&to_date={$to}");

        $response->assertStatus(422);
    }
}
