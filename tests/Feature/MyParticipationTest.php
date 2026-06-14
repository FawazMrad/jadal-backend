<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyParticipationTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledDebate(): Debate
    {
        return Debate::factory()->create([
            'status'       => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    public function test_approved_debater_sees_approved_status(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $debater->id,
            'role'      => 'debater',
            'side'      => 'proposition',
            'status'    => 'approved',
        ]);

        $response = $this->actingAs($debater)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertEquals('approved', $row['my_participation_status']);
    }

    public function test_non_registered_debater_sees_not_registered(): void
    {
        $debate = $this->scheduledDebate();
        $other  = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($other)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertEquals('not_registered', $row['my_participation_status']);
    }

    public function test_pending_debater_sees_pending_status(): void
    {
        $debate  = $this->scheduledDebate();
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id,
            'user_id'   => $debater->id,
            'role'      => 'debater',
            'side'      => 'proposition',
            'status'    => 'pending',
        ]);

        $response = $this->actingAs($debater)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertEquals('pending', $row['my_participation_status']);
    }

    public function test_non_debater_has_no_participation_field(): void
    {
        $debate  = $this->scheduledDebate();
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        $response = $this->actingAs($trainer)->getJson('/api/debates?status=scheduled');

        $row = collect($response->json('data'))->firstWhere('id', $debate->id);
        $this->assertArrayNotHasKey('my_participation_status', $row);
    }
}
