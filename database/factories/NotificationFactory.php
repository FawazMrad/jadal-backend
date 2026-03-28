<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $types = [
            'debate_scheduled'  => 'تمت جدولة نقاش جديد',
            'debate_result'     => 'نتيجة النقاش',
            'feedback_received' => 'تلقيت تقييماً جديداً',
            'team_invitation'   => 'دعوة للانضمام إلى فريق',
            'survey_available'  => 'استبيان جديد متاح',
        ];

        $typeKey = fake()->randomElement(array_keys($types));

        return [
            'user_id'    => User::factory(),
            'type'       => $typeKey,
            'title'      => $types[$typeKey],
            'body'       => fake()->sentence(10),
            'data'       => fake()->optional(0.6)->passthrough(['entity_id' => fake()->numberBetween(1, 100)]),
            'read_at'    => fake()->optional(0.5)->dateTimeBetween('-1 month', 'now'),
            'created_at' => fake()->dateTimeBetween('-2 months', 'now'),
        ];
    }
}
