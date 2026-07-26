<?php

namespace Database\Factories;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    protected $model = Achievement::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->words(3, true),
            'type'      => fake()->randomElement(Achievement::TYPES),
            'image_url' => null,
        ];
    }
}
