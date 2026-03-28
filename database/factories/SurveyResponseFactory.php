<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyResponse>
 */
class SurveyResponseFactory extends Factory
{
    protected $model = SurveyResponse::class;

    public function definition(): array
    {
        return [
            'survey_id'  => Survey::factory(),
            'user_id'    => User::factory(),
            'answers'    => [
                'q1' => fake()->randomElement(['موافق', 'محايد', 'غير موافق']),
                'q2' => fake()->numberBetween(1, 10),
                'q3' => fake()->sentence(8),
            ],
            'created_at' => fake()->dateTimeBetween('-3 months', 'now'),
        ];
    }
}
