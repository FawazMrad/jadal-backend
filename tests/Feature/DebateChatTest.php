<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprinkles §2 — persistent team chat with read receipts. */
class DebateChatTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Debate, 1: Team, 2: User, 3: User, 4: Team, 5: User} */
    private function makeDebateWithTwoTeams(): array
    {
        $debate = Debate::factory()->create(['status' => 'live']);

        $teamA = Team::factory()->create();
        $a1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $a2 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        foreach ([$a1, $a2] as $u) {
            DebateParticipant::create([
                'debate_id' => $debate->id, 'user_id' => $u->id, 'team_id' => $teamA->id,
                'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
                'is_chair' => false, 'is_attended' => false,
            ]);
        }

        $teamB = Team::factory()->create();
        $b1 = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $b1->id, 'team_id' => $teamB->id,
            'role' => 'debater', 'side' => 'opposition', 'status' => 'approved',
            'is_chair' => false, 'is_attended' => false,
        ]);

        return [$debate, $teamA, $a1, $a2, $teamB, $b1];
    }

    public function test_message_persists_and_teammate_sees_it_with_seen_by(): void
    {
        [$debate, , $a1, $a2] = $this->makeDebateWithTwoTeams();

        $res = $this->actingAs($a1)->postJson("/api/debates/{$debate->id}/chat", [
            'message' => 'let us open with definitions',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.sender_id', $a1->id);
        $res->assertJsonPath('data.seen_by', [$a1->id]); // sender always in seen_by

        // Teammate rejoins and fetches history — the message is there.
        $get = $this->actingAs($a2)->getJson("/api/debates/{$debate->id}/chat");
        $get->assertStatus(200);
        $get->assertJsonCount(1, 'data.messages');
        $get->assertJsonPath('data.messages.0.message', 'let us open with definitions');
        $get->assertJsonPath('data.messages.0.sender_name', $a1->name);
        $get->assertJsonPath('data.messages.0.seen_by', [$a1->id]); // a2 has not read yet
    }

    public function test_mark_read_adds_caller_to_seen_by(): void
    {
        [$debate, , $a1, $a2] = $this->makeDebateWithTwoTeams();

        $this->actingAs($a1)->postJson("/api/debates/{$debate->id}/chat", ['message' => 'one']);
        $this->actingAs($a1)->postJson("/api/debates/{$debate->id}/chat", ['message' => 'two']);

        $read = $this->actingAs($a2)->postJson("/api/debates/{$debate->id}/chat/read");
        $read->assertStatus(200);
        $read->assertJsonPath('data.marked_count', 2);

        $get = $this->actingAs($a2)->getJson("/api/debates/{$debate->id}/chat");
        foreach ([0, 1] as $i) {
            $seenBy = $get->json("data.messages.{$i}.seen_by");
            $this->assertContains($a2->id, $seenBy);
        }

        // Idempotent — nothing new to mark.
        $this->actingAs($a2)->postJson("/api/debates/{$debate->id}/chat/read")
            ->assertJsonPath('data.marked_count', 0);
    }

    public function test_chat_is_team_scoped_never_cross_team(): void
    {
        [$debate, , $a1, , , $b1] = $this->makeDebateWithTwoTeams();

        $this->actingAs($a1)->postJson("/api/debates/{$debate->id}/chat", ['message' => 'team A secret']);

        $get = $this->actingAs($b1)->getJson("/api/debates/{$debate->id}/chat");
        $get->assertStatus(200);
        $get->assertJsonCount(0, 'data.messages'); // opposing team sees nothing
    }

    public function test_participants_without_a_team_have_no_chat(): void
    {
        [$debate] = $this->makeDebateWithTwoTeams();

        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $judge->id, 'team_id' => null,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'is_attended' => false,
        ]);

        $this->actingAs($judge)->getJson("/api/debates/{$debate->id}/chat")->assertStatus(403);
        $this->actingAs($judge)->postJson("/api/debates/{$debate->id}/chat", ['message' => 'hi'])->assertStatus(403);

        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $this->actingAs($outsider)->getJson("/api/debates/{$debate->id}/chat")->assertStatus(403);
    }
}
