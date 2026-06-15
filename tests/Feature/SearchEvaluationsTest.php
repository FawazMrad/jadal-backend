<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchEvaluationsTest extends TestCase
{
    use RefreshDatabase;

    private function trainerWithEvaluations(): User
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);

        Evaluation::factory()->create(['trainer_id' => $trainer->id, 'notes' => 'Strong analytical reasoning shown']);
        Evaluation::factory()->create(['trainer_id' => $trainer->id, 'notes' => 'Improve time management in speeches']);

        return $trainer;
    }

    public function test_search_filters_by_notes(): void
    {
        $trainer = $this->trainerWithEvaluations();
        $response = $this->actingAs($trainer)->getJson('/api/evaluations?search=analytical');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        $trainer = $this->trainerWithEvaluations();
        $response = $this->actingAs($trainer)->getJson('/api/evaluations');
        $this->assertCount(2, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        $trainer = $this->trainerWithEvaluations();
        $response = $this->actingAs($trainer)->getJson('/api/evaluations?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $response = $this->actingAs($trainer)->getJson('/api/evaluations?search=a');
        $response->assertStatus(422);
    }
}
