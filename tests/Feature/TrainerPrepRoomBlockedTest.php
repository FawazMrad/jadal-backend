<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use App\Services\LiveKitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainerPrepRoomBlockedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoomIfMissing')->andReturnNull();
            $mock->shouldReceive('generateRoomToken')->andReturn('fake.jwt.token');
        });
    }

    private function makeLobbyDebate(): array
    {
        $format = DebateFormat::factory()->create([
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);

        $debate = Debate::factory()->create([
            'format_id'            => $format->id,
            'status'              => 'teams-selected',
            'current_stage'       => 0,
            'prep_rooms_opened_at' => now()->subMinutes(10),
            'prop_room_name'      => 'debate-prep-prop',
            'opp_room_name'       => 'debate-prep-opp',
        ]);

        $leader = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team   = Team::factory()->create(['leader_id' => $leader->id, 'is_random' => false]);

        // 3 prop debaters belonging to the team.
        $debaters = [];
        for ($i = 0; $i < 3; $i++) {
            $u = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            DebateParticipant::factory()->create([
                'debate_id' => $debate->id, 'user_id' => $u->id,
                'role' => 'debater', 'side' => 'proposition', 'team_id' => $team->id,
                'status' => 'approved',
            ]);
            $debaters[] = $u;
        }

        // The team trainer is a participant tied to the team.
        $trainer = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $trainer->id,
            'role' => 'trainer', 'side' => 'trainer', 'team_id' => $team->id,
            'status' => 'approved',
        ]);

        return [$debate, $trainer, $debaters];
    }

    public function test_team_trainer_is_blocked_from_prep_room(): void
    {
        [$debate, $trainer] = $this->makeLobbyDebate();

        $response = $this->actingAs($trainer)->getJson("/api/debates/{$debate->id}/token?room=prop");

        $response->assertStatus(403);
    }

    public function test_debater_on_side_can_join_prep_room(): void
    {
        [$debate, $trainer, $debaters] = $this->makeLobbyDebate();

        $response = $this->actingAs($debaters[0])->getJson("/api/debates/{$debate->id}/token?room=prop");

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_in_room', 'debater');
    }
}
