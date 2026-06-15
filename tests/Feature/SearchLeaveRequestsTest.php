<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamLeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchLeaveRequestsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team}
     */
    private function teamWithRequests(): array
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team    = Team::factory()->create(['created_by' => $trainer->id, 'leader_id' => $trainer->id]);

        $m1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $m2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        TeamLeaveRequest::create(['team_id' => $team->id, 'user_id' => $m1->id, 'status' => 'pending', 'reason' => 'Moving to another city']);
        TeamLeaveRequest::create(['team_id' => $team->id, 'user_id' => $m2->id, 'status' => 'pending', 'reason' => 'Schedule conflict with studies']);

        return [$trainer, $team];
    }

    public function test_search_filters_by_reason(): void
    {
        [$trainer, $team] = $this->teamWithRequests();
        $response = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}/leave-requests?search=studies");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_no_search_returns_all(): void
    {
        [$trainer, $team] = $this->teamWithRequests();
        $response = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}/leave-requests");
        $this->assertCount(2, $response->json('data'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        [$trainer, $team] = $this->teamWithRequests();
        $response = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}/leave-requests?search=zzzznope");
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        [$trainer, $team] = $this->teamWithRequests();
        $response = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}/leave-requests?search=a");
        $response->assertStatus(422);
    }
}
