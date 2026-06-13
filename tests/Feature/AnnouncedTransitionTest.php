<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncedTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function scheduledDebate(): Debate
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

        return Debate::factory()->create([
            'format_id'    => $format->id,
            'status'       => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    private function debaterPayload(string $side, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $u = User::factory()->create(['role' => 'debater', 'status' => 'active']);
            $out[] = ['user_id' => $u->id, 'role' => 'debater', 'side' => $side, 'status' => 'approved'];
        }
        return $out;
    }

    public function test_status_flips_to_announced_when_both_sides_and_judge_assigned(): void
    {
        $debate = $this->scheduledDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $participants = array_merge(
            $this->debaterPayload('proposition', 3),
            $this->debaterPayload('opposition', 3),
            [['user_id' => $judge->id, 'role' => 'judge', 'side' => 'judge', 'status' => 'approved', 'judge_order' => 1]],
        );

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => $participants,
        ]);

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertEquals('announced', $debate->status);
    }

    public function test_status_stays_scheduled_with_only_one_side(): void
    {
        $debate = $this->scheduledDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $judge  = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        // Only proposition + judge — no opposition.
        $participants = array_merge(
            $this->debaterPayload('proposition', 3),
            [['user_id' => $judge->id, 'role' => 'judge', 'side' => 'judge', 'status' => 'approved', 'judge_order' => 1]],
        );

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => $participants,
        ]);

        $response->assertStatus(200);
        $debate->refresh();
        $this->assertEquals('scheduled', $debate->status);
    }

    public function test_judges_without_explicit_order_get_monotonic_orders(): void
    {
        $debate = $this->scheduledDebate();
        $admin  = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $j1     = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        $j2     = User::factory()->create(['role' => 'judge', 'status' => 'active']);

        $response = $this->actingAs($admin)->postJson("/api/admin/debates/{$debate->id}/participants", [
            'participants' => [
                ['user_id' => $j1->id, 'role' => 'judge', 'side' => 'judge', 'status' => 'approved'],
                ['user_id' => $j2->id, 'role' => 'judge', 'side' => 'judge', 'status' => 'approved'],
            ],
        ]);

        $response->assertStatus(200);

        $orders = \App\Models\DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->pluck('judge_order')
            ->sort()
            ->values()
            ->all();

        $this->assertEquals([1, 2], $orders);
    }
}
