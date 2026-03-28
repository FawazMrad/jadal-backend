<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    private static array $arabicNames = [
        'أحمد محمد', 'فاطمة علي', 'محمد خالد', 'نورة عبدالله', 'عمر سعد',
        'سارة يوسف', 'خالد عبدالرحمن', 'منى حسين', 'عبدالله إبراهيم', 'ريم عبدالعزيز',
        'يوسف ناصر', 'لمياء أحمد', 'سلطان محمد', 'هيا العمر', 'تركي الشمري',
        'دلال الحربي', 'بدر القحطاني', 'نوف الغامدي', 'وليد الزهراني', 'حصة الدوسري',
        'فيصل العتيبي', 'رنا الأحمد', 'ماجد الحمدان', 'شيماء المطيري', 'ابراهيم السلمي',
        'هند الرشيد', 'عبدالعزيز الفهد', 'مريم القحطاني', 'سعود النجدي', 'لطيفة الكندي',
        'زياد المنصور', 'أمل الشهراني', 'عادل الجهني', 'رغد الزيد', 'طارق السبيعي',
        'جواهر الحربي', 'حمزة الدوسري', 'غادة العسيري', 'نواف الثبيتي', 'ولاء السليم',
    ];

    public function definition(): array
    {
        return [
            'name'              => fake()->randomElement(self::$arabicNames),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'role'              => fake()->randomElement(['debater', 'trainer', 'judge', 'admin']),
            'status'            => fake()->randomElement(['active', 'active', 'active', 'suspended', 'banned']),
            'avatar_url'        => fake()->optional(0.4)->imageUrl(200, 200, 'people'),
            'phone'             => fake()->optional(0.7)->numerify('05########'),
            'points'            => fake()->numberBetween(0, 500),
            'livekit_token'     => null,
            'remember_token'    => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin', 'status' => 'active']);
    }

    public function trainer(): static
    {
        return $this->state(fn () => ['role' => 'trainer', 'status' => 'active']);
    }

    public function judge(): static
    {
        return $this->state(fn () => ['role' => 'judge', 'status' => 'active']);
    }

    public function debater(): static
    {
        return $this->state(fn () => ['role' => 'debater', 'status' => 'active']);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
