<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogCategory>
 */
class BlogCategoryFactory extends Factory
{
    protected $model = BlogCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'المناظرة والجدال', 'مهارات التواصل', 'التفكير النقدي',
            'Debate Techniques', 'Public Speaking', 'Research & Evidence',
            'أخبار النادي', 'مسابقات ونتائج', 'Club News',
        ]);

        return [
            'name'       => $name,
            'slug'       => Str::slug($name) ?: Str::slug(fake()->unique()->word()),
            'created_at' => now(),
        ];
    }
}
