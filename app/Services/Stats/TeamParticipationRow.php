<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * One (team × completed debate) row — the team-level analogue of ParticipationRow.
 *
 * `avgScore` is main-stage-only (orders 1-6) because the leaderboard and the
 * coach team-summary have always computed it that way and their published
 * numbers must not move. The richer `speeches` list carries every scored stage
 * INCLUDING replies, and is what the bucketed metrics and the
 * combination analysis aggregate over — those mirror the debater API's
 * semantics instead. The two therefore differ by the reply stages; that is
 * deliberate and documented in the API reply, not an oversight.
 *
 * @phpstan-type Speech array{user_id:int, code:string, score:float, is_reply:bool}
 */
class TeamParticipationRow
{
    /**
     * @param  int[]  $frameworkIds
     * @param  array<int, array{user_id:int, code:string, score:float, is_reply:bool}>  $speeches
     * @param  array<int, string>  $frameworkLabels  id => name
     */
    public function __construct(
        public readonly int $debateId,
        public readonly CarbonInterface $date,
        public readonly float $won,       // 1.0 win / 0.5 draw / 0.0 loss
        public readonly ?float $avgScore, // main-stage mean, or null
        public readonly array $frameworkIds = [],
        public readonly array $speeches = [],
        public readonly string $side = 'proposition',
        public readonly array $frameworkLabels = [],
    ) {}

    /** Distinct slot codes this team's speakers occupied (e.g. ['P1','P2','PR']). */
    public function positionsHeld(): array
    {
        return array_values(array_unique(array_column($this->speeches, 'code')));
    }

    /**
     * Mean score across the team's speeches, restricted to a slot set
     * (null = every slot). Null when no speech matches, so the debate drops out
     * of that filtered series rather than counting as a zero.
     */
    public function normalizedScore(?array $slotSet): ?float
    {
        $relevant = $slotSet === null
            ? $this->speeches
            : array_filter($this->speeches, fn (array $s) => in_array($s['code'], $slotSet, true));

        if (empty($relevant)) {
            return null;
        }

        $scores = array_column($relevant, 'score');

        return array_sum($scores) / count($scores);
    }

    /**
     * The line-up: distinct user ids who actually spoke, ascending.
     *
     * Sorted so the set is order-insensitive — {A@P1, B@P2} and {B@P1, A@P2}
     * produce the same combination key, which is what Q3 asks for.
     *
     * @return int[]
     */
    public function speakerIds(): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $s) => (int) $s['user_id'],
            $this->speeches
        )));
        sort($ids);

        return $ids;
    }

    /** Mean score for one member in this debate, or null if they did not speak. */
    public function scoreForMember(int $userId): ?float
    {
        $scores = array_column(
            array_filter($this->speeches, fn (array $s) => (int) $s['user_id'] === $userId),
            'score'
        );

        return empty($scores) ? null : array_sum($scores) / count($scores);
    }
}
