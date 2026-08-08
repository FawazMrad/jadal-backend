<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\User;
use App\Notifications\PushType;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1 debate_state_changed must fire on the TERMINAL transitions.
 *
 * These were silent until recently — a debate could go live, finish or be
 * cancelled without telling anyone. Cancellation is the one that matters:
 * participants would otherwise turn up to a debate that no longer exists.
 *
 * The trigger points are spread across four files, so these guard against
 * someone adding a status write without the matching notification.
 */
class DebateStateChangedPushTest extends TestCase
{
    use RefreshDatabase;

    /** Records sends instead of talking to FCM. */
    private function spyOnPush(): object
    {
        $spy = new class extends PushService {
            public array $sent = [];

            public function sendToUsers(iterable $userIds, string $type, array $data = [], array $replacements = []): void
            {
                $this->sent[] = ['type' => $type, 'status' => $replacements['status'] ?? null];
            }
        };

        $this->app->instance(PushService::class, $spy);
        $this->app->forgetInstance(\App\Services\Push\DebateNotifier::class);

        return $spy;
    }

    /** @return array<int, string|null> the status of every #1 send */
    private function stateChanges(object $spy): array
    {
        return array_values(array_map(
            fn (array $s) => $s['status'],
            array_filter($spy->sent, fn (array $s) => $s['type'] === PushType::DEBATE_STATE_CHANGED)
        ));
    }

    private function chairOf(Debate $debate): User
    {
        $chair = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $chair->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
            'is_chair' => true, 'judge_order' => 1,
        ]);

        return $chair;
    }

    public function test_cancelling_via_close_room_sends_exactly_one_state_change(): void
    {
        $spy    = $this->spyOnPush();
        $debate = Debate::factory()->create(['status' => 'live', 'current_stage' => 1]);
        $chair  = $this->chairOf($debate);

        // No result stored → close-room cancels the debate.
        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/close-room")
            ->assertStatus(200);

        $this->assertSame('cancelled', $debate->fresh()->status);
        $this->assertSame(['cancelled'], $this->stateChanges($spy));
    }

    /**
     * NOTE: this deliberately does NOT go through submitResult.
     * Submitting a result does not complete a debate — it stays `live` for the
     * result phase, and close-room is what finalises it. close-room WITH a
     * stored result is therefore the only path to `completed`.
     */
    public function test_completing_via_close_room_sends_exactly_one_state_change(): void
    {
        $spy    = $this->spyOnPush();
        $debate = Debate::factory()->create(['status' => 'live', 'current_stage' => 1]);
        $chair  = $this->chairOf($debate);

        DebateResult::create([
            'debate_id'    => $debate->id,
            'judge_id'     => $chair->id,
            'winning_side' => 'proposition',
            'scores'       => ['stages' => []],
            'submitted_at' => now(),
        ]);

        $this->actingAs($chair)
            ->postJson("/api/debates/{$debate->id}/close-room")
            ->assertStatus(200);

        $this->assertSame('completed', $debate->fresh()->status);
        $this->assertSame(['completed'], $this->stateChanges($spy));
    }

    public function test_starting_a_debate_sends_exactly_one_state_change(): void
    {
        $spy    = $this->spyOnPush();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $debate = Debate::factory()->create(['status' => 'scheduled']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/debates/{$debate->id}/start")
            ->assertStatus(200);

        $this->assertSame('live', $debate->fresh()->status);
        $this->assertSame(['live'], $this->stateChanges($spy));
    }
}
