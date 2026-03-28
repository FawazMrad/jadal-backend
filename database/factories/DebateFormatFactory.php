<?php

namespace Database\Factories;

use App\Models\DebateFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateFormat>
 */
class DebateFormatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'         => fake()->unique()->randomElement([
                'British Parliamentary',
                'Asian Parliamentary',
                'Karl Popper',
                'Lincoln-Douglas',
                'Oxford Union',
                'World Schools',
                'Public Forum',
                'IPDA',
            ]),
            'description'  => fake()->optional()->sentence(12),
            'phase_config' => [
                'phases' => [
                    ['name' => 'Opening', 'duration' => 420],
                    ['name' => 'Rebuttal', 'duration' => 300],
                    ['name' => 'Summary', 'duration' => 240],
                ],
                'speakers_per_side' => fake()->randomElement([2, 3, 4]),
            ],
        ];
    }
}
