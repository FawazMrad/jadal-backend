<?php

namespace Database\Factories;

use App\Models\MotionFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MotionFramework>
 */
class MotionFrameworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'       => fake()->unique()->randomElement([
                'Policy',
                'Value',
                'Fact',
                'Comparative Advantage',
                'مبدأ العدالة',
                'الأخلاق التطبيقية',
                'السياسة العامة',
                'الحقوق والحريات',
            ]),
            'color_hex'  => fake()->hexColor(),
            'created_at' => now(),
        ];
    }
}
