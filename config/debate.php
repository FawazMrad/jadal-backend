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
    ],
];
