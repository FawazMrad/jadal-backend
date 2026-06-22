<?php

namespace Tests\Concerns;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Motion;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;

/**
 * Builds completed debates using the REAL scores JSON shape produced by
 * LiveDebateController::submitResult — {stages: [{stage_order, participant_id,
 * user_id, score}], notes} — NOT the stale by-side shape in DebateResultFactory.
 */
trait MakesCompletedDebates
{
    private ?User $statsJudge = null;

    private function judgeUser(): User
    {
        return $this->statsJudge ??= User::factory()->create(['role' => 'judge', 'status' => 'active']);
    }

    /**
     * Create one completed, result-revealed debate the focal debater played in.
     *
     * Options:
     *   side          'proposition'|'opposition'   (focal side; default proposition)
     *   date          'Y-m-d'                       (scheduled_at; default 2025-03-10)
     *   winning_side  'proposition'|'opposition'    (default = side, i.e. a win)
     *   main          int   focal main-stage score  (default 80)
     *   reply         ?int  focal reply score       (default null = no reply)
     *   other_main    int   opponent main score     (default 0; for best-speaker max)
     *   framework_ids int[] motion frameworks       (default [])
     *   team          ?Team focal team              (default null = RANDOM)
     *   motion        string motion text            (default 'Test motion')
     */
    private function completedDebate(User $debater, array $o = []): Debate
    {
        $side       = $o['side'] ?? 'proposition';
        $date       = $o['date'] ?? '2025-03-10';
        $winning    = $o['winning_side'] ?? $side;
        $main       = $o['main'] ?? 80;
        $reply      = $o['reply'] ?? null;
        $otherMain  = $o['other_main'] ?? 0;
        $team       = $o['team'] ?? null;
        $fwIds      = $o['framework_ids'] ?? [];

        $motion = Motion::factory()->create(['text' => $o['motion'] ?? 'Test motion']);
        if (! empty($fwIds)) {
            $motion->frameworks()->attach($fwIds);
        }

        $debate = Debate::factory()->create([
            'status'             => 'completed',
            'motion_id'          => $motion->id,
            'scheduled_at'       => Carbon::parse($date),
            'started_at'         => Carbon::parse($date),
            'ended_at'           => Carbon::parse($date)->addHours(2),
            'result_revealed_at' => Carbon::parse($date)->addHours(2),
        ]);

        $focal = DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $debater->id, 'team_id' => $team?->id,
            'role' => 'debater', 'side' => $side, 'status' => 'approved',
            'speaking_phase_order' => 1, 'is_chair' => false, 'is_attended' => true,
        ]);

        $oppUser = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $oppSide = $side === 'proposition' ? 'opposition' : 'proposition';
        $oppP = DebateParticipant::create([
            'debate_id' => $debate->id, 'user_id' => $oppUser->id,
            'role' => 'debater', 'side' => $oppSide, 'status' => 'approved',
            'speaking_phase_order' => 1, 'is_chair' => false, 'is_attended' => true,
        ]);

        $focalMainOrder = $side === 'proposition' ? 1 : 2;   // P1=1, O1=2
        $otherMainOrder = $side === 'proposition' ? 2 : 1;
        $stages = [
            ['stage_order' => $focalMainOrder, 'participant_id' => $focal->id, 'user_id' => $debater->id, 'score' => $main],
            ['stage_order' => $otherMainOrder, 'participant_id' => $oppP->id,  'user_id' => $oppUser->id, 'score' => $otherMain],
        ];
        if ($reply !== null) {
            $replyOrder = $side === 'proposition' ? 8 : 7; // PR=8, OR=7
            $stages[] = ['stage_order' => $replyOrder, 'participant_id' => $focal->id, 'user_id' => $debater->id, 'score' => $reply];
        }

        DebateResult::create([
            'debate_id'     => $debate->id,
            'judge_id'      => $this->judgeUser()->id,
            'winning_side'  => $winning,
            'scores'        => ['stages' => $stages, 'notes' => null],
            'summary_notes' => null,
            'submitted_at'  => Carbon::parse($date)->addHours(2),
        ]);

        return $debate;
    }

    private function debater(): User
    {
        return User::factory()->create(['role' => 'debater', 'status' => 'active']);
    }

    private function coachWithSupervisee(User $debater): User
    {
        $coach = User::factory()->create(['role' => 'trainer', 'status' => 'active']);
        $team = Team::factory()->create(['created_by' => $coach->id, 'is_random' => false, 'status' => 'active']);
        \App\Models\TeamMember::create([
            'team_id' => $team->id, 'user_id' => $debater->id, 'priority' => 1, 'status' => 'current',
        ]);

        return $coach;
    }
}
