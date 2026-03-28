<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    public function definition(): array
    {
        $allRoles    = ['debater', 'trainer', 'judge', 'admin'];
        $targetCount = fake()->numberBetween(1, 4);
        $targetRoles = fake()->randomElements($allRoles, $targetCount);

        return [
            'created_by'   => User::factory()->admin(),
            'title'        => fake()->sentence(6),
            'description'  => fake()->optional()->paragraph(),
            'target_roles' => $targetRoles,
            'closes_at'    => fake()->optional(0.7)->dateTimeBetween('+1 day', '+3 months'),
        ];
    }
}
