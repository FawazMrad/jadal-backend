<?php

namespace Database\Factories;

use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogTag>
 */
class BlogTagFactory extends Factory
{
    protected $model = BlogTag::class;

    private static array $tagNames = [
        'مناظرة', 'خطابة', 'تفكير نقدي', 'مهارات', 'شباب',
        'Debate', 'Speech', 'Logic', 'Argumentation', 'Students',
        'نادي جدل', 'بطولة', 'تدريب', 'تقييم', 'فريق',
    ];

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(self::$tagNames);

        return [
            'name'       => $name,
            'slug'       => Str::slug($name) ?: 'tag-' . fake()->unique()->numberBetween(1, 999),
            'created_at' => now(),
        ];
    }
}
