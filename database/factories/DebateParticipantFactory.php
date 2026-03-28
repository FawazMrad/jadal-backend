<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateParticipant>
 */
class DebateParticipantFactory extends Factory
{
    protected $model = DebateParticipant::class;

    public function definition(): array
    {
        $role = fake()->randomElement(['debater', 'trainer', 'judge', 'viewer']);

        $sideMap = [
            'debater' => fake()->randomElement(['proposition', 'opposition']),
            'judge'   => 'judge',
            'trainer' => 'trainer',
            'viewer'  => 'viewer',
        ];

        return [
            'debate_id'           => Debate::factory(),
            'user_id'             => User::factory(),
            'team_id'             => $role === 'debater' ? Team::factory() : null,
            'role'                => $role,
            'side'                => $sideMap[$role],
            'status'              => fake()->randomElement(['pending', 'approved', 'approved', 'approved']),
            'is_chair'            => $role === 'judge' && fake()->boolean(30),
            'is_attended'         => fake()->boolean(80),
            'speaking_phase_order' => $role === 'debater' ? fake()->numberBetween(1, 4) : null,
        ];
    }
}
