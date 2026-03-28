<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['open', 'under_review', 'resolved', 'dismissed']);

        return [
            'filed_by'       => User::factory(),
            'debate_id'      => fake()->optional(0.7)->passthrough(Debate::factory()->completed()),
            'description'    => fake()->paragraph(2),
            'status'         => $status,
            'admin_response' => in_array($status, ['resolved', 'dismissed'])
                ? fake()->sentence(15)
                : null,
        ];
    }
}
