<?php

namespace Database\Factories;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    public function definition(): array
    {
        $title     = fake()->sentence(fake()->numberBetween(4, 8));
        $status    = fake()->randomElement(['draft', 'pending_review', 'published', 'published', 'rejected']);
        $publishedAt = $status === 'published' ? fake()->dateTimeBetween('-6 months', 'now') : null;

        return [
            'author_id'       => User::factory(),
            'title'           => $title,
            'slug'            => Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 9999),
            'content'         => implode("\n\n", fake()->paragraphs(fake()->numberBetween(4, 8))),
            'status'          => $status,
            'cover_image_url' => fake()->optional(0.6)->imageUrl(800, 400, 'abstract'),
            'reviewer_comment' => $status === 'rejected' ? fake()->sentence(10) : null,
            'published_at'    => $publishedAt,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status'       => 'published',
            'published_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }
}
