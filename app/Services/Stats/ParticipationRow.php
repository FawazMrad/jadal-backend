<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * One flattened (debater × completed debate) participation record. Every stat is
 * a fast aggregate over a collection of these.
 *
 * @phpstan-type Stage array{order:int, code:string, is_reply:bool, score:float}
 */
class ParticipationRow
{
    /**
     * @param  array<int, array{order:int, code:string, is_reply:bool, score:float}>  $stages
     * @param  int[]  $frameworkIds
     */
    public function __construct(
        public readonly int $debateId,
        public readonly CarbonInterface $date,
        public readonly array $frameworkIds,
        public readonly string $side,
        public readonly string $winningSide,
        public readonly bool $won,
        public readonly string $teamKey,    // team id as string, or 'RANDOM'
        public readonly ?int $teamId,
        public readonly array $stages,      // this debater's scored stages
        public readonly bool $isBestSpeaker,
        public readonly string $motionText,
        public readonly array $frameworkLabels = [], // id => name
    ) {}

    /** Distinct slot codes this debater spoke (e.g. ['P1','PR']). */
    public function positionsHeld(): array
    {
        return array_values(array_unique(array_map(fn ($s) => $s['code'], $this->stages)));
    }

    /**
     * Normalized per-debate score restricted to the given slot set (null = all
     * stages). Returns null when the debater has no stage matching the set, in
     * which case the debate contributes nothing for that filter.
     *
     * NOTE: no reply ×2 transform — this platform scores replies on the same
     * 0..max scale as main speeches (see PR notes / SubmitResultRequest).
     */
    public function normalizedScore(?array $slotSet): ?float
    {
        $relevant = $slotSet === null
            ? $this->stages
            : array_filter($this->stages, fn ($s) => in_array($s['code'], $slotSet, true));

        if (empty($relevant)) {
            return null;
        }

        $scores = array_map(fn ($s) => $s['score'], $relevant);

        return array_sum($scores) / count($scores);
    }

    /** Highest main (non-reply) stage score this debater earned, if any. */
    public function mainScore(): ?float
    {
        $main = array_filter($this->stages, fn ($s) => ! $s['is_reply']);

        return empty($main) ? null : max(array_map(fn ($s) => $s['score'], $main));
    }

    /** Reply stage score this debater earned, if they gave a reply. */
    public function replyScore(): ?float
    {
        foreach ($this->stages as $s) {
            if ($s['is_reply']) {
                return $s['score'];
            }
        }

        return null;
    }
}
