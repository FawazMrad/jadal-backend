<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evaluation>
 */
class EvaluationFactory extends Factory
{
    protected $model = Evaluation::class;

    public function definition(): array
    {
        return [
            'trainer_id' => User::factory()->trainer(),
            'debater_id' => User::factory()->debater(),
            'debate_id'  => fake()->optional(0.7)->passthrough(Debate::factory()->completed()),
            'notes'      => fake()->paragraph(2),
            'scores'     => [
                'critical_thinking' => fake()->numberBetween(1, 10),
                'public_speaking'   => fake()->numberBetween(1, 10),
                'research_skills'   => fake()->numberBetween(1, 10),
                'teamwork'          => fake()->numberBetween(1, 10),
                'overall'           => fake()->numberBetween(1, 10),
            ],
        ];
    }
}
