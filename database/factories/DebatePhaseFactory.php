<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebatePhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebatePhase>
 */
class DebatePhaseFactory extends Factory
{
    protected $model = DebatePhase::class;

    public function definition(): array
    {
        $status    = fake()->randomElement(['pending', 'active', 'completed']);
        $startedAt = null;
        $endedAt   = null;

        if (in_array($status, ['active', 'completed'])) {
            $startedAt = fake()->dateTimeBetween('-2 hours', '-30 minutes');
        }

        if ($status === 'completed') {
            $endedAt = fake()->dateTimeBetween($startedAt, 'now');
        }

        return [
            'debate_id'        => Debate::factory(),
            'name'             => fake()->randomElement([
                'Opening Statement', 'First Rebuttal', 'Cross Examination',
                'Summary', 'Final Focus', 'كلمة الافتتاح', 'الرد الأول', 'الخاتمة',
            ]),
            'order_index'      => fake()->numberBetween(1, 6),
            'duration_seconds' => fake()->randomElement([180, 240, 300, 360, 420, 480]),
            'status'           => $status,
            'started_at'       => $startedAt,
            'ended_at'         => $endedAt,
        ];
    }
}
