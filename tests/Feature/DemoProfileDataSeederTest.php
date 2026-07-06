<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\DemoProfileDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** V2 §6 — the data-seeding ask must run cleanly and be idempotent. */
class DemoProfileDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_profile_fields_achievements_and_a_team_swap(): void
    {
        $debaters = User::factory()->count(8)->create(['role' => 'debater', 'status' => 'active', 'birth_date' => null, 'location' => null]);
        $teamA = Team::factory()->create(['is_random' => false]);
        $teamB = Team::factory()->create(['is_random' => false]);
        TeamMember::create(['team_id' => $teamA->id, 'user_id' => $debaters[0]->id, 'priority' => 1, 'status' => 'current']);

        $this->seed(DemoProfileDataSeeder::class);

        foreach ($debaters as $d) {
            $this->assertNotNull($d->fresh()->birth_date);
            $this->assertNotNull($d->fresh()->location);
        }

        $this->assertGreaterThan(0, Achievement::count());

        // The swap moved debaters[0] off teamA onto a different current team.
        $memberships = TeamMember::where('user_id', $debaters[0]->id)->get();
        $this->assertTrue($memberships->contains(fn ($m) => $m->status === 'past' && $m->team_id === $teamA->id));
        $this->assertTrue($memberships->contains(fn ($m) => $m->status === 'current' && $m->team_id !== $teamA->id));
    }

    public function test_seeder_is_idempotent(): void
    {
        User::factory()->count(3)->create(['role' => 'debater', 'status' => 'active']);
        Team::factory()->count(2)->create(['is_random' => false]);

        $this->seed(DemoProfileDataSeeder::class);
        $achievementCountAfterFirst = Achievement::count();
        $pastMembershipCountAfterFirst = TeamMember::where('status', 'past')->count();

        $this->seed(DemoProfileDataSeeder::class);

        $this->assertEquals($achievementCountAfterFirst, Achievement::count());
        $this->assertEquals($pastMembershipCountAfterFirst, TeamMember::where('status', 'past')->count());
    }
}
