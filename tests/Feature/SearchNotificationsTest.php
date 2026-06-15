<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithNotifications(): User
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        Notification::factory()->create(['user_id' => $user->id, 'title' => 'Debate scheduled tomorrow', 'body' => 'Your debate starts soon']);
        Notification::factory()->create(['user_id' => $user->id, 'title' => 'New feedback received',     'body' => 'A judge left you a note']);

        return $user;
    }

    public function test_search_filters_by_title(): void
    {
        $user = $this->userWithNotifications();
        $response = $this->actingAs($user)->getJson('/api/notifications?search=scheduled');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_search_matches_body(): void
    {
        $user = $this->userWithNotifications();
        $response = $this->actingAs($user)->getJson('/api/notifications?search=judge');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        $user = $this->userWithNotifications();
        $response = $this->actingAs($user)->getJson('/api/notifications');
        $this->assertCount(2, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        $user = $this->userWithNotifications();
        $response = $this->actingAs($user)->getJson('/api/notifications?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $user = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $response = $this->actingAs($user)->getJson('/api/notifications?search=a');
        $response->assertStatus(422);
    }
}
