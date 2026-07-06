<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/** One (team × completed debate) row — the team-level analogue of ParticipationRow. */
class TeamParticipationRow
{
    public function __construct(
        public readonly int $debateId,
        public readonly CarbonInterface $date,
        public readonly float $won,       // 1.0 win / 0.5 draw / 0.0 loss
        public readonly ?float $avgScore, // average of the team's speakers' main scores, or null
    ) {}
}
