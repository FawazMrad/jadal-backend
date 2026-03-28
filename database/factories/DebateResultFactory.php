<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateResult>
 */
class DebateResultFactory extends Factory
{
    protected $model = DebateResult::class;

    public function definition(): array
    {
        return [
            'debate_id'    => Debate::factory()->completed(),
            'judge_id'     => User::factory()->judge(),
            'winning_side' => fake()->randomElement(['proposition', 'opposition', 'draw']),
            'scores'       => [
                'proposition' => [
                    'content'      => fake()->numberBetween(60, 100),
                    'delivery'     => fake()->numberBetween(60, 100),
                    'strategy'     => fake()->numberBetween(60, 100),
                ],
                'opposition' => [
                    'content'      => fake()->numberBetween(60, 100),
                    'delivery'     => fake()->numberBetween(60, 100),
                    'strategy'     => fake()->numberBetween(60, 100),
                ],
            ],
            'summary_notes' => fake()->optional()->paragraph(),
            'submitted_at'  => fake()->dateTimeBetween('-2 months', 'now'),
        ];
    }
}
