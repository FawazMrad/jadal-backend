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
        $hasReply = fake()->boolean();

        return [
            'name'         => fake()->unique()->words(3, true),
            'description'  => fake()->optional()->sentence(12),
            'phase_config' => [
                'speech_time_seconds'         => fake()->randomElement([300, 360, 420, 480]),
                'has_reply_speech'             => $hasReply,
                'reply_time_seconds'           => $hasReply ? 240 : 0,
                'protected_time_seconds'       => 60,
                'motion_reveal_offset_hours'   => fake()->randomElement([0.5, 1, 24]),
                'prep_rooms_open_offset_hours' => fake()->randomElement([0.5, 1]),
            ],
        ];
    }

    public function withReply(): static
    {
        return $this->state(fn () => [
            'phase_config' => [
                'speech_time_seconds'         => 420,
                'has_reply_speech'             => true,
                'reply_time_seconds'           => 240,
                'protected_time_seconds'       => 60,
                'motion_reveal_offset_hours'   => 0.5,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }

    public function withoutReply(): static
    {
        return $this->state(fn () => [
            'phase_config' => [
                'speech_time_seconds'         => 300,
                'has_reply_speech'             => false,
                'reply_time_seconds'           => 0,
                'motion_reveal_offset_hours'   => 1,
                'prep_rooms_open_offset_hours' => 0.5,
            ],
        ]);
    }
}
