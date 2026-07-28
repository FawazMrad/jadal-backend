<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /teams for debaters (search-to-join) + the debater join-request / trainer approve-reject flow. */
class TeamJoinRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_debater_search_excludes_current_random_and_inactive_teams(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $joinable  = Team::factory()->create(['status' => 'active', 'is_random' => false]);
        $current   = Team::factory()->create(['status' => 'active', 'is_random' => false]);
        $random    = Team::factory()->create(['status' => 'active', 'is_random' => true]);
        $inactive  = Team::factory()->create(['status' => 'inactive', 'is_random' => false]);

        TeamMember::create(['team_id' => $current->id, 'user_id' => $debater->id, 'priority' => 1, 'status' => 'current']);

        $res = $this->actingAs($debater)->getJson('/api/teams');
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($joinable->id, $ids);
        $this->assertNotContains($current->id, $ids);
        $this->assertNotContains($random->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_judge_still_blocked_from_teams_index(): void
    {
        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $this->actingAs($judge)->getJson('/api/teams')->assertStatus(403);
    }

    public function test_debater_can_submit_join_request(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create(['status' => 'active', 'is_random' => false]);

        $res = $this->actingAs($debater)->postJson("/api/teams/{$team->id}/join", ['reason' => 'I want in']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('team_join_requests', [
            'team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'pending',
        ]);
    }

    public function test_join_request_rejects_random_inactive_already_member_and_duplicate(): void
    {
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $randomTeam = Team::factory()->create(['status' => 'active', 'is_random' => true]);
        $this->actingAs($debater)->postJson("/api/teams/{$randomTeam->id}/join")->assertStatus(422);

        $inactiveTeam = Team::factory()->create(['status' => 'inactive', 'is_random' => false]);
        $this->actingAs($debater)->postJson("/api/teams/{$inactiveTeam->id}/join")->assertStatus(422);

        $memberTeam = Team::factory()->create(['status' => 'active', 'is_random' => false]);
        TeamMember::create(['team_id' => $memberTeam->id, 'user_id' => $debater->id, 'priority' => 1, 'status' => 'current']);
        $this->actingAs($debater)->postJson("/api/teams/{$memberTeam->id}/join")->assertStatus(409);

        $team = Team::factory()->create(['status' => 'active', 'is_random' => false]);
        $this->actingAs($debater)->postJson("/api/teams/{$team->id}/join")->assertStatus(200);
        $this->actingAs($debater)->postJson("/api/teams/{$team->id}/join")->assertStatus(409);
    }

    public function test_non_debater_cannot_submit_join_request(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team    = Team::factory()->create(['status' => 'active', 'is_random' => false]);

        $this->actingAs($trainer)->postJson("/api/teams/{$team->id}/join")->assertStatus(403);
    }

    public function test_owning_trainer_can_list_and_accept_join_request_creating_membership(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create(['created_by' => $trainer->id, 'status' => 'active', 'is_random' => false]);

        $submit = $this->actingAs($debater)->postJson("/api/teams/{$team->id}/join");
        $requestId = $submit->json('data.id');

        $list = $this->actingAs($trainer)->getJson("/api/teams/{$team->id}/join-requests");
        $list->assertStatus(200)->assertJsonCount(1, 'data');

        $accept = $this->actingAs($trainer)->patchJson(
            "/api/teams/{$team->id}/join-requests/{$requestId}/respond",
            ['status' => 'accepted']
        );
        $accept->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'current',
        ]);
    }

    public function test_reject_does_not_create_membership_and_responding_twice_fails(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create(['created_by' => $trainer->id, 'status' => 'active', 'is_random' => false]);

        $joinRequest = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'pending']);

        $reject = $this->actingAs($trainer)->patchJson(
            "/api/teams/{$team->id}/join-requests/{$joinRequest->id}/respond",
            ['status' => 'rejected']
        );
        $reject->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $debater->id]);

        $this->actingAs($trainer)->patchJson(
            "/api/teams/{$team->id}/join-requests/{$joinRequest->id}/respond",
            ['status' => 'accepted']
        )->assertStatus(409);
    }

    public function test_non_owning_trainer_cannot_view_or_respond(): void
    {
        $owner   = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $other   = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create(['created_by' => $owner->id, 'status' => 'active', 'is_random' => false]);

        $joinRequest = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'pending']);

        $this->actingAs($other)->getJson("/api/teams/{$team->id}/join-requests")->assertStatus(403);
        $this->actingAs($other)->patchJson(
            "/api/teams/{$team->id}/join-requests/{$joinRequest->id}/respond",
            ['status' => 'accepted']
        )->assertStatus(403);
    }

    public function test_accepting_reactivates_a_past_membership_instead_of_duplicating(): void
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $team    = Team::factory()->create(['created_by' => $trainer->id, 'status' => 'active', 'is_random' => false]);

        TeamMember::create(['team_id' => $team->id, 'user_id' => $debater->id, 'priority' => 3, 'status' => 'past']);

        $joinRequest = TeamJoinRequest::create(['team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'pending']);

        $this->actingAs($trainer)->patchJson(
            "/api/teams/{$team->id}/join-requests/{$joinRequest->id}/respond",
            ['status' => 'accepted']
        )->assertStatus(200);

        $this->assertDatabaseCount('team_members', 1);
        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id, 'user_id' => $debater->id, 'status' => 'current',
        ]);
    }
}
