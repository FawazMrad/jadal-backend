<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    private static array $teamNames = [
        'فريق الفجر', 'فريق النسر', 'فريق الصقر', 'فريق البرق',
        'فريق الأمل', 'فريق الريادة', 'فريق القمة', 'فريق التحدي',
        'Team Phoenix', 'Team Vanguard', 'Team Nexus', 'Team Apex',
        'فريق المستقبل', 'فريق الإبداع', 'فريق التميز',
    ];

    public function definition(): array
    {
        return [
            'name'       => fake()->unique()->randomElement(self::$teamNames),
            'leader_id'  => User::factory()->debater(),
            'created_by' => User::factory()->admin(),
            'status'     => fake()->randomElement(['active', 'active', 'active', 'inactive']),
            'is_random'  => false,
        ];
    }
}
