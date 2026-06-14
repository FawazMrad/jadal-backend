<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\Motion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function unrevealedScheduledDebate(): Debate
    {
        $motion = Motion::factory()->create(['text' => 'Secret upcoming motion']);

        return Debate::factory()->create([
            'status'             => 'scheduled',
            'motion_id'          => $motion->id,
            'motion_revealed_at' => null, // not yet revealed
            'scheduled_at'       => now()->addDay(),
        ]);
    }

    public function test_debater_does_not_see_unrevealed_motion(): void
    {
        $debate  = $this->unrevealedScheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($debater)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertNotNull($row);
        $this->assertNull($row['motion']);
    }

    public function test_admin_sees_unrevealed_motion_text(): void
    {
        $debate = $this->unrevealedScheduledDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertNotNull($row['motion']);
        $this->assertEquals('Secret upcoming motion', $row['motion']['text']);
    }

    public function test_completed_debate_motion_is_visible_to_everyone(): void
    {
        $motion = Motion::factory()->create(['text' => 'Archived motion']);
        $debate = Debate::factory()->create([
            'status'             => 'completed',
            'motion_id'          => $motion->id,
            'motion_revealed_at' => null, // even without the timestamp, archive shows it
            'scheduled_at'       => now()->subDays(3),
        ]);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($debater)->getJson('/api/debates?status=completed');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertNotNull($row['motion']);
        $this->assertEquals('Archived motion', $row['motion']['text']);
    }
}
