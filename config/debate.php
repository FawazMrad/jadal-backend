<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Score range
    |--------------------------------------------------------------------------
    |
    | The actual scale chair-submitted stage scores are stored on. The platform
    | currently validates stage scores as integers 0..100 (SubmitResultRequest),
    | so that is the default here. The stats module derives every scale-dependent
    | constant from this range rather than hardcoding the spec's 59-90 numbers.
    |
    */
    'score_range' => [
        'min' => (int) env('DEBATE_SCORE_MIN', 0),
        'max' => (int) env('DEBATE_SCORE_MAX', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-debate rating scale
    |--------------------------------------------------------------------------
    |
    | The 1..5 stars submitted via POST /feedback with type=rating_judgement or
    | rating_debate. Must stay in step with StoreFeedbackRequest's
    | scores.rating min/max rule — the judge-rating endpoint publishes this as
    | `rating_scale` so the client renders the right number of stars instead of
    | hardcoding it.
    |
    */
    'rating_scale' => [
        'min' => (int) env('DEBATE_RATING_MIN', 1),
        'max' => (int) env('DEBATE_RATING_MAX', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics constants (improvement index)
    |--------------------------------------------------------------------------
    |
    | The spec's improvement-index magic numbers were derived for the main
    | speech scale 59..90 (span = 31):
    |   - consistency denominator 15.5 = 31 / 2
    |   - score_norm divisor 3        (±3 pts/bucket -> ±50)
    |   - winrate_norm divisor 0.1    (scale-independent proportion)
    |
    | We keep the SAME relative sensitivity but scale the score-based divisors to
    | the configured range via k = (max - min) / spec_reference_span:
    |   - consistency_denominator = 15.5 * k = (max - min) / 2
    |   - score_norm_divisor      = 3    * k
    |   - winrate_norm_divisor    = 0.1  (unchanged — winrate is a proportion)
    |
    */
    'stats' => [
        'spec_reference_span'          => 31,   // 90 - 59
        'spec_consistency_denominator' => 15.5, // 31 / 2
        'spec_score_norm_divisor'      => 3,
        'spec_winrate_norm_divisor'    => 0.1,

        // group_by=month is rejected without explicit from/to when the data span
        // exceeds this many months (prevents unbounded payloads).
        'month_span_guard'             => 24,

        // Improvement index needs at least this many non-empty buckets.
        'min_buckets_for_index'        => 3,

        // Switch improvement granularity from monthly to yearly past this span.
        'improvement_month_to_year_span' => 24,

        // Ceiling on zero-filled activity buckets, so an absurd
        // from/to range can't generate an unbounded payload.
        'max_zero_filled_buckets'      => 240,

        // team combination analysis.
        'combination_default_min_debates' => 2,
        'combination_default_limit'       => 10,
        'combination_max_limit'           => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Points system
    |--------------------------------------------------------------------------
    |
    | Elo-style: delta = base + K*(actual - expected) + score_component.
    |   expected = 1 / (1 + 10^((opponent_rating - own_rating) / 400))
    |   actual   = 1 win / 0.5 draw / 0 loss
    | own_rating / opponent_rating are the participant's / opposing team's
    | CURRENT `points` value — there is no separate hidden Elo rating, so the
    | displayed number and the rating used for the calculation are always the
    | same thing (auditable via `points_histories`).
    |
    */
    'points' => [
        'k_elo'               => (int) env('POINTS_K_ELO', 24),
        'base_participation'  => (int) env('POINTS_BASE_PARTICIPATION', 4),
        // score_component = clamp(round((score - baseline) / divisor), -clamp, clamp)
        'score_baseline'      => (int) env('POINTS_SCORE_BASELINE', 70),
        'score_divisor'       => (int) env('POINTS_SCORE_DIVISOR', 5),
        'score_clamp'         => (int) env('POINTS_SCORE_CLAMP', 8),
        'min_points'          => (int) env('POINTS_MIN', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity/participation scoring
    |--------------------------------------------------------------------------
    |
    | Flat per-event weights, NOT Elo-adjusted (registering/attending/viewing
    | isn't a contest). Judge/coach attendance & misses weigh heaviest (their
    | absence has the biggest structural impact); debater attendance next;
    | registration/viewing smallest (they're "positive interest" signals).
    |
    */
    'activity' => [
        'registration_points' => (float) env('ACTIVITY_REGISTRATION_POINTS', 1),
        'viewing_points'      => (float) env('ACTIVITY_VIEWING_POINTS', 0.5),
        'attendance_points'   => [
            'debater' => (float) env('ACTIVITY_ATTENDANCE_DEBATER', 3),
            'trainer' => (float) env('ACTIVITY_ATTENDANCE_TRAINER', 5),
            'judge'   => (float) env('ACTIVITY_ATTENDANCE_JUDGE', 6),
        ],
        'penalty_points' => [
            'debater' => (float) env('ACTIVITY_PENALTY_DEBATER', -2),
            'trainer' => (float) env('ACTIVITY_PENALTY_TRAINER', -6),
            'judge'   => (float) env('ACTIVITY_PENALTY_JUDGE', -8),
        ],
    ],
];
