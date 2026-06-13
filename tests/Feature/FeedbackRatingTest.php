<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Feedbacks;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackRatingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Debate, 1: User, 2: User} [debate, debater, judge]
     */
    private function revealedDebate(bool $revealed = true): array
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
            'format_id'          => $format->id,
            'status'             => 'completed',
            'result_revealed_at' => $revealed ? now() : null,
        ]);

        $debater = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $debater->id,
            'role' => 'debater', 'side' => 'proposition', 'status' => 'approved',
        ]);

        $judge = User::factory()->create(['role' => 'judge', 'status' => 'active']);
        DebateParticipant::factory()->create([
            'debate_id' => $debate->id, 'user_id' => $judge->id,
            'role' => 'judge', 'side' => 'judge', 'status' => 'approved',
        ]);

        return [$debate, $debater, $judge];
    }

    public function test_participant_can_rate_the_debate(): void
    {
        [$debate, $debater] = $this->revealedDebate();

        $response = $this->actingAs($debater)->postJson('/api/feedback', [
            'debate_id' => $debate->id,
            'type'      => 'rating_debate',
            'scores'    => ['rating' => 4],
            'content'   => 'Good debate.',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('feedbacks', [
            'debate_id' => $debate->id,
            'from_user_id' => $debater->id,
            'type' => 'rating_debate',
        ]);
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        [$debate, $debater] = $this->revealedDebate();

        $response = $this->actingAs($debater)->postJson('/api/feedback', [
            'debate_id' => $debate->id,
            'type'      => 'rating_debate',
            'scores'    => ['rating' => 6],
        ]);

        $response->assertStatus(422);
    }

    public function test_participant_can_rate_a_judges_judgement(): void
    {
        [$debate, $debater, $judge] = $this->revealedDebate();

        $response = $this->actingAs($debater)->postJson('/api/feedback', [
            'debate_id'  => $debate->id,
            'type'       => 'rating_judgement',
            'to_user_id' => $judge->id,
            'scores'     => ['rating' => 5],
        ]);

        $response->assertStatus(201);
    }

    public function test_rating_judgement_requires_a_real_judge(): void
    {
        [$debate, $debater] = $this->revealedDebate();
        $stranger = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($debater)->postJson('/api/feedback', [
            'debate_id'  => $debate->id,
            'type'       => 'rating_judgement',
            'to_user_id' => $stranger->id,
            'scores'     => ['rating' => 3],
        ]);

        $response->assertStatus(422);
    }

    public function test_rating_blocked_before_result_revealed(): void
    {
        [$debate, $debater] = $this->revealedDebate(revealed: false);

        $response = $this->actingAs($debater)->postJson('/api/feedback', [
            'debate_id' => $debate->id,
            'type'      => 'rating_debate',
            'scores'    => ['rating' => 4],
        ]);

        $response->assertStatus(422);
    }

    public function test_non_participant_cannot_rate(): void
    {
        [$debate] = $this->revealedDebate();
        $outsider = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $response = $this->actingAs($outsider)->postJson('/api/feedback', [
            'debate_id' => $debate->id,
            'type'      => 'rating_debate',
            'scores'    => ['rating' => 4],
        ]);

        $response->assertStatus(422);
    }
}
