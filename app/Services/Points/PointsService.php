<?php

namespace App\Services\Points;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\PointsHistory;
use App\Models\Team;
use App\Models\User;

/**
 * V2 §3 — the points system. Elo-style: a win against a higher-rated opponent
 * awards more than a win against a lower-rated one, and vice versa. There is
 * no separate hidden Elo rating — `users.points` / `teams.points` themselves
 * ARE the ratings used for the expected-outcome calculation, so the number a
 * user sees is always exactly what's being computed with.
 *
 * Triggered once per debate, from LiveDebateController::closeRoom() at the
 * exact moment status flips to 'completed' (the same eligibility bar the
 * existing stats pipeline already uses: status=completed AND a result
 * exists). Idempotent — guarded by a unique (subject_type, subject_id,
 * debate_id) index, so calling this twice for the same debate is a no-op.
 */
class PointsService
{
    public function awardForDebate(Debate $debate): void
    {
        $result = $debate->result;
        if (! $result) {
            return;
        }

        // Idempotency guard — already awarded for this debate.
        if (PointsHistory::where('debate_id', $debate->id)->exists()) {
            return;
        }

        $stages = $result->scores['stages'] ?? [];

        $debaters = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'debater')
            ->where('status', 'approved')
            ->get();

        foreach ($debaters as $participant) {
            $this->awardDebater($debate, $participant, $stages, $result->winning_side);
        }

        if ($debate->proposition_team_id && $debate->opposition_team_id) {
            $this->awardTeam($debate, 'proposition', $stages, $result->winning_side);
            $this->awardTeam($debate, 'opposition', $stages, $result->winning_side);
        }
    }

    private function awardDebater(Debate $debate, DebateParticipant $participant, array $stages, string $winningSide): void
    {
        $user = User::find($participant->user_id);
        if (! $user) {
            return;
        }

        $ownTeamId = $participant->team_id;
        $opponentTeamId = match ($participant->side) {
            'proposition' => $debate->opposition_team_id,
            'opposition'  => $debate->proposition_team_id,
            default       => null,
        };
        $opponentRating = $opponentTeamId ? (int) (Team::find($opponentTeamId)?->points ?? $user->points) : $user->points;

        $score = $this->mainScoreForUser($stages, $participant->user_id);
        $actual = $this->actualScore($participant->side, $winningSide);

        [$delta, $breakdown] = $this->computeDelta($user->points, $opponentRating, $actual, $score);

        $this->apply($user, $debate, $delta, $breakdown);
    }

    private function awardTeam(Debate $debate, string $side, array $stages, string $winningSide): void
    {
        $teamId = $side === 'proposition' ? $debate->proposition_team_id : $debate->opposition_team_id;
        $opponentTeamId = $side === 'proposition' ? $debate->opposition_team_id : $debate->proposition_team_id;

        $team = Team::find($teamId);
        if (! $team) {
            return;
        }
        $opponentRating = (int) (Team::find($opponentTeamId)?->points ?? $team->points);

        $memberIds = DebateParticipant::where('debate_id', $debate->id)
            ->where('team_id', $teamId)
            ->where('role', 'debater')
            ->pluck('user_id');

        $scores = [];
        foreach ($memberIds as $uid) {
            $s = $this->mainScoreForUser($stages, $uid);
            if ($s !== null) {
                $scores[] = $s;
            }
        }
        $avgScore = empty($scores) ? null : array_sum($scores) / count($scores);

        $actual = $this->actualScore($side, $winningSide);

        [$delta, $breakdown] = $this->computeDelta($team->points, $opponentRating, $actual, $avgScore);

        $this->apply($team, $debate, $delta, $breakdown);
    }

    /** @return array{0: int, 1: array} [delta, breakdown] */
    private function computeDelta(int $ownRating, int $opponentRating, float $actual, ?float $score): array
    {
        $cfg = config('debate.points');

        $expected = 1 / (1 + (10 ** (($opponentRating - $ownRating) / 400)));
        $eloDelta = (int) round($cfg['k_elo'] * ($actual - $expected));

        $scoreComponent = 0;
        if ($score !== null) {
            $scoreComponent = (int) round(($score - $cfg['score_baseline']) / $cfg['score_divisor']);
            $scoreComponent = max(-$cfg['score_clamp'], min($cfg['score_clamp'], $scoreComponent));
        }

        $delta = $cfg['base_participation'] + $eloDelta + $scoreComponent;

        return [$delta, [
            'base'             => $cfg['base_participation'],
            'elo_delta'        => $eloDelta,
            'elo_expected'     => round($expected, 4),
            'opponent_rating'  => $opponentRating,
            'score'            => $score,
            'score_component'  => $scoreComponent,
        ]];
    }

    private function apply(User|Team $subject, Debate $debate, int $delta, array $breakdown): void
    {
        $before = (int) $subject->points;
        $after  = max((int) config('debate.points.min_points'), $before + $delta);

        $subject->update(['points' => $after]);

        PointsHistory::create([
            'subject_type'  => $subject::class,
            'subject_id'    => $subject->id,
            'debate_id'     => $debate->id,
            'points_before' => $before,
            'delta'         => $after - $before, // reflects the min-points floor if clamped
            'points_after'  => $after,
            'breakdown'     => $breakdown,
            'created_at'    => now(),
        ]);
    }

    private function actualScore(?string $side, string $winningSide): float
    {
        if ($winningSide === 'draw') {
            return 0.5;
        }

        return $side === $winningSide ? 1.0 : 0.0;
    }

    /** Highest non-reply (stage_order <= 6) stage score for this user, or null. */
    private function mainScoreForUser(array $stages, int $userId): ?float
    {
        $scores = [];
        foreach ($stages as $stage) {
            if ((int) ($stage['user_id'] ?? 0) !== $userId) {
                continue;
            }
            if ((int) ($stage['stage_order'] ?? 0) > 6) {
                continue; // reply stage — excluded from the points scoring component
            }
            if (isset($stage['score'])) {
                $scores[] = (float) $stage['score'];
            }
        }

        return empty($scores) ? null : max($scores);
    }
}
