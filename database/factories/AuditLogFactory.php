<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        $actions = [
            'created', 'updated', 'deleted', 'status_changed',
            'login', 'logout', 'approved', 'rejected',
        ];

        $entities = ['Debate', 'User', 'Team', 'BlogPost', 'Survey', 'Complaint', 'Motion'];

        return [
            'user_id'     => fake()->optional(0.9)->passthrough(User::factory()),
            'action'      => fake()->randomElement($actions),
            'entity_type' => fake()->randomElement($entities),
            'entity_id'   => fake()->numberBetween(1, 500),
            'old_values'  => fake()->optional(0.6)->passthrough(['status' => 'pending']),
            'new_values'  => fake()->optional(0.8)->passthrough(['status' => 'approved']),
            'ip_address'  => fake()->ipv4(),
            'created_at'  => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
