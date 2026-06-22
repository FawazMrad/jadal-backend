<?php

namespace App\Services\Stats;

/**
 * Position-code mapping and expansion for the debater statistics module.
 *
 * Stage order → slot code (reply order is inverted vs main order, matching the
 * platform's deriveStages()):
 *   1=P1 2=O1 3=P2 4=O2 5=P3 6=O3   (main stages 1-6)
 *   7=OR 8=PR                        (reply: Opposition Reply first, then Prop)
 */
class PositionCodes
{
    /** Slot-level codes (8). */
    public const SLOTS = ['P1', 'P2', 'P3', 'PR', 'O1', 'O2', 'O3', 'OR'];

    /** Codes that involve a reply stage — rejected on the best-speaker endpoint. */
    public const REPLY_CODES = ['PR', 'OR', 'R'];

    private const STAGE_ORDER_TO_CODE = [
        1 => 'P1', 2 => 'O1', 3 => 'P2', 4 => 'O2', 5 => 'P3', 6 => 'O3',
        7 => 'OR', 8 => 'PR',
    ];

    /** Role-level and side-level codes → the slot set they expand to. */
    private const GROUP_EXPANSIONS = [
        '1' => ['P1', 'O1'],
        '2' => ['P2', 'O2'],
        '3' => ['P3', 'O3'],
        'R' => ['PR', 'OR'],
        'P' => ['P1', 'P2', 'P3', 'PR'],
        'O' => ['O1', 'O2', 'O3', 'OR'],
    ];

    public static function codeForStageOrder(int $order): ?string
    {
        return self::STAGE_ORDER_TO_CODE[$order] ?? null;
    }

    public static function isReplyOrder(int $order): bool
    {
        return $order === 7 || $order === 8;
    }

    /** Every code a client may pass in a `positions` filter. */
    public static function allValidCodes(): array
    {
        return array_merge(self::SLOTS, array_keys(self::GROUP_EXPANSIONS));
    }

    /** Expand a single code to its slot set. */
    public static function expand(string $code): array
    {
        if (in_array($code, self::SLOTS, true)) {
            return [$code];
        }

        return self::GROUP_EXPANSIONS[$code] ?? [];
    }

    /** Expand a list of codes to a deduplicated union of slot codes. */
    public static function expandMany(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            foreach (self::expand($code) as $slot) {
                $out[$slot] = true;
            }
        }

        return array_keys($out);
    }

    /** True if any of the codes references a reply stage. */
    public static function containsReply(array $codes): bool
    {
        return ! empty(array_intersect($codes, self::REPLY_CODES));
    }
}
