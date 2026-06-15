<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\Feedbacks;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function userWithFeedback(): User
    {
        $user   = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $debate = Debate::factory()->create();
        $target = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        foreach (['Excellent rebuttal and clear delivery', 'Needs stronger evidence next time'] as $content) {
            Feedbacks::create([
                'debate_id'    => $debate->id,
                'from_user_id' => $user->id,
                'to_user_id'   => $target->id,
                'type'         => 'judge_to_debater',
                'content'      => $content,
                'scores'       => ['argumentation' => 8],
            ]);
        }

        return $user;
    }

    public function test_search_filters_by_content(): void
    {
        $user = $this->userWithFeedback();
        $response = $this->actingAs($user)->getJson('/api/feedback?search=rebuttal');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        $user = $this->userWithFeedback();
        $response = $this->actingAs($user)->getJson('/api/feedback');
        $this->assertCount(2, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        $user = $this->userWithFeedback();
        $response = $this->actingAs($user)->getJson('/api/feedback?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $user = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $response = $this->actingAs($user)->getJson('/api/feedback?search=a');
        $response->assertStatus(422);
    }
}
