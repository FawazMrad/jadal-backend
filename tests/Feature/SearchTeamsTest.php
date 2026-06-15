<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTeamsTest extends TestCase
{
    use RefreshDatabase;

    private function trainerWithTeams(): User
    {
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        Team::factory()->create(['name' => 'Falcons Squad',  'created_by' => $trainer->id, 'leader_id' => $trainer->id]);
        Team::factory()->create(['name' => 'Eagles United',  'created_by' => $trainer->id, 'leader_id' => $trainer->id]);
        Team::factory()->create(['name' => 'Lions Pride',    'created_by' => $trainer->id, 'leader_id' => $trainer->id]);

        return $trainer;
    }

    public function test_no_search_returns_all_own_teams(): void
    {
        $trainer = $this->trainerWithTeams();
        $response = $this->actingAs($trainer)->getJson('/api/teams');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_search_filters_by_name(): void
    {
        $trainer = $this->trainerWithTeams();
        $response = $this->actingAs($trainer)->getJson('/api/teams?search=Eagles');
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Eagles United', $response->json('data.0.name'));
    }

    public function test_nonexistent_returns_empty(): void
    {
        $trainer = $this->trainerWithTeams();
        $response = $this->actingAs($trainer)->getJson('/api/teams?search=zzzznope');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_single_char_rejected(): void
    {
        $trainer = $this->trainerWithTeams();
        $response = $this->actingAs($trainer)->getJson('/api/teams?search=a');
        $response->assertStatus(422);
    }
}
