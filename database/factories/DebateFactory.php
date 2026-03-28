<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\Motion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Debate>
 */
class DebateFactory extends Factory
{
    public function definition(): array
    {
        $scheduledAt = fake()->dateTimeBetween('-3 months', '+1 month');
        $status      = fake()->randomElement(['scheduled', 'announced', 'teams-selected', 'live', 'completed', 'cancelled']);

        $startedAt = null;
        $endedAt   = null;

        if (in_array($status, ['live', 'completed'])) {
            $startedAt = fake()->dateTimeBetween($scheduledAt, '+2 hours');
        }

        if ($status === 'completed') {
            $endedAt = fake()->dateTimeBetween($startedAt, '+3 hours');
        }

        return [
            'format_id'        => DebateFormat::factory(),
            'motion_id'        => Motion::factory(),
            'created_by'       => User::factory()->admin(),
            'title'            => fake()->sentence(6),
            'description'      => fake()->optional()->paragraph(),
            'status'           => $status,
            'livekit_room_name' => 'room-' . Str::uuid(),
            'recording_url'    => $status === 'completed' ? fake()->optional(0.7)->url() : null,
            'transcript'       => $status === 'completed' ? fake()->optional(0.5)->paragraphs(3, true) : null,
            'scheduled_at'     => $scheduledAt,
            'started_at'       => $startedAt,
            'ended_at'         => $endedAt,
        ];
    }

    public function completed(): static
    {
        return $this->state(function () {
            $scheduledAt = fake()->dateTimeBetween('-3 months', '-1 week');
            $startedAt   = fake()->dateTimeBetween($scheduledAt, '+2 hours');
            $endedAt     = fake()->dateTimeBetween($startedAt, '+3 hours');

            return [
                'status'       => 'completed',
                'scheduled_at' => $scheduledAt,
                'started_at'   => $startedAt,
                'ended_at'     => $endedAt,
                'recording_url' => fake()->url(),
            ];
        });
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status'       => 'scheduled',
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
            'started_at'   => null,
            'ended_at'     => null,
        ]);
    }
}
