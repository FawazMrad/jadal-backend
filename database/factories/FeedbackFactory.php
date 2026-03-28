<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['judge_to_debater', 'trainer_to_debater', 'debater_on_session']);

        return [
            'debate_id'    => Debate::factory()->completed(),
            'from_user_id' => User::factory(),
            'to_user_id'   => $type !== 'debater_on_session' ? User::factory() : null,
            'type'         => $type,
            'content'      => fake()->paragraph(3),
            'scores'       => $type !== 'debater_on_session' ? [
                'argumentation' => fake()->numberBetween(1, 10),
                'delivery'      => fake()->numberBetween(1, 10),
                'evidence'      => fake()->numberBetween(1, 10),
                'rebuttal'      => fake()->numberBetween(1, 10),
            ] : null,
        ];
    }
}
