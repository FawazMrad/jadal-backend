<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Motion;
use App\Models\MotionFramework;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Debate search + filter option endpoints. */
class DebateSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_q_matches_title_or_motion_text(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $byTitle  = Debate::factory()->create(['title' => 'The Climate Question', 'status' => 'completed']);
        $motion   = Motion::factory()->create(['text' => 'This House would tax climate emissions heavily']);
        $byMotion = Debate::factory()->create(['title' => 'Round 2', 'motion_id' => $motion->id, 'status' => 'completed']);
        Debate::factory()->create(['title' => 'Unrelated', 'status' => 'completed']);

        $res = $this->actingAs($viewer)->getJson('/api/debates/search?q=climate');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($byTitle->id, $ids);
        $this->assertContains($byMotion->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_filters_combine_and_across_dimensions(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $format = DebateFormat::factory()->create();
        $judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $team   = Team::factory()->create();

        $match = Debate::factory()->create([
            'format_id' => $format->id, 'tag' => 'season-1',
            'status' => 'completed', 'scheduled_at' => now()->subDays(5),
        ]);
        DebateParticipant::create([
            'debate_id' => $match->id, 'user_id' => $judge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved', 'is_chair' => true, 'is_attended' => false,
        ]);
        $match->update(['proposition_team_id' => $team->id]);

        // Same format but wrong judge/tag — must not match.
        Debate::factory()->create(['format_id' => $format->id, 'tag' => 'other', 'status' => 'completed']);

        $query = http_build_query([
            'format_id' => [$format->id],
            'debate_tag' => ['season-1'],
            'judge_id' => [$judge->id],
            'team_id' => [$team->id],
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $res = $this->actingAs($viewer)->getJson("/api/debates/search?{$query}");
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$match->id], $ids);
    }

    public function test_user_filter_finds_debates_they_took_part_in(): void
    {
        $viewer  = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $mine = Debate::factory()->create(['status' => 'completed']);
        DebateParticipant::create([
            'debate_id' => $mine->id, 'user_id' => $debater->id, 'team_id' => null,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved', 'is_chair' => false, 'is_attended' => false,
        ]);
        Debate::factory()->create(['status' => 'completed']); // not theirs

        $res = $this->actingAs($viewer)->getJson('/api/debates/search?user_id[]=' . $debater->id);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_framework_filter_via_motion_frameworks(): void
    {
        $viewer    = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $framework = MotionFramework::factory()->create();
        $motion    = Motion::factory()->create();
        $motion->frameworks()->attach($framework->id);

        $match = Debate::factory()->create(['motion_id' => $motion->id, 'status' => 'completed']);
        Debate::factory()->create(['status' => 'completed']);

        $res = $this->actingAs($viewer)->getJson('/api/debates/search?framework_id[]=' . $framework->id);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$match->id], $ids);
    }

    public function test_option_list_endpoints(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        Debate::factory()->create(['tag' => 'season-1']);
        Debate::factory()->create(['tag' => 'season-1']); // duplicate collapses
        Debate::factory()->create(['tag' => 'friendly']);

        $tags = $this->actingAs($viewer)->getJson('/api/debates/tags/distinct');
        $tags->assertStatus(200);
        $this->assertEqualsCanonicalizing(['season-1', 'friendly'], $tags->json('data.tags'));

        User::factory()->create(['role' => 'judge', 'status' => 'active', 'name' => 'Judge Judy']);
        $judges = $this->actingAs($viewer)->getJson('/api/judges');
        $judges->assertStatus(200);
        $this->assertContains('Judge Judy', collect($judges->json('data'))->pluck('name')->all());

        Team::factory()->create(['is_random' => true,  'status' => 'active']);
        Team::factory()->create(['is_random' => false, 'status' => 'active']);
        $options = $this->actingAs($viewer)->getJson('/api/teams/options?is_random=0');
        $options->assertStatus(200);
        foreach ($options->json('data') as $team) {
            $this->assertFalse($team['is_random']);
        }
    }

    public function test_teams_index_filters_now_work_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        Team::factory()->create(['is_random' => true,  'status' => 'active']);
        Team::factory()->create(['is_random' => false, 'status' => 'active']);
        Team::factory()->create(['is_random' => false, 'status' => 'inactive']);

        $random = $this->actingAs($admin)->getJson('/api/teams?is_random=1');
        $random->assertStatus(200);
        $this->assertCount(1, $random->json('data'));

        $inactive = $this->actingAs($admin)->getJson('/api/teams?status=inactive');
        $inactive->assertStatus(200);
        $this->assertCount(1, $inactive->json('data'));
    }
}
