<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyQuestion>
 */
class SurveyQuestionFactory extends Factory
{
    protected $model = SurveyQuestion::class;

    public function definition(): array
    {
        $type    = fake()->randomElement(['mcq', 'rating', 'open_text']);
        $options = null;

        if ($type === 'mcq') {
            $options = fake()->randomElements([
                'موافق تماماً', 'موافق', 'محايد', 'غير موافق', 'غير موافق تماماً',
                'Strongly Agree', 'Agree', 'Neutral', 'Disagree', 'Strongly Disagree',
            ], fake()->numberBetween(3, 5));
        } elseif ($type === 'rating') {
            $options = ['min' => 1, 'max' => 10, 'step' => 1];
        }

        return [
            'survey_id'     => Survey::factory(),
            'question_text' => fake()->sentence(8) . '؟',
            'type'          => $type,
            'options'       => $options,
            'order_index'   => fake()->numberBetween(1, 10),
        ];
    }
}
