<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sprinkles §10 — blog search/filters + option-list endpoints. */
class BlogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_q_matches_title_or_content(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $byTitle   = BlogPost::factory()->published()->create(['title' => 'Winning with rebuttals']);
        $byContent = BlogPost::factory()->published()->create(['title' => 'Другое', 'content' => 'The art of rebuttals explained.']);
        BlogPost::factory()->published()->create(['title' => 'Unrelated', 'content' => 'Nothing here.']);

        $res = $this->actingAs($viewer)->getJson('/api/blog?q=rebuttals');
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$byTitle->id, $byContent->id], $ids);
    }

    public function test_publisher_and_liked_by_me_filters(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $author = User::factory()->create(['role' => 'debater', 'status' => 'active']);

        $mine  = BlogPost::factory()->published()->create(['author_id' => $author->id]);
        $liked = BlogPost::factory()->published()->create();
        BlogPost::factory()->published()->create();

        DB::table('blog_post_reactions')->insert([
            'post_id' => $liked->id, 'user_id' => $viewer->id, 'type' => 'like', 'created_at' => now(),
        ]);

        $byAuthor = $this->actingAs($viewer)->getJson('/api/blog?publisher_id[]=' . $author->id);
        $this->assertSame([$mine->id], collect($byAuthor->json('data'))->pluck('id')->all());

        $likedRes = $this->actingAs($viewer)->getJson('/api/blog?liked_by_me=1');
        $this->assertSame([$liked->id], collect($likedRes->json('data'))->pluck('id')->all());
    }

    public function test_option_list_endpoints_are_reachable_by_any_auth_user(): void
    {
        $viewer = User::factory()->create(['role' => 'debater', 'status' => 'active']);
        $author = User::factory()->create(['status' => 'active', 'name' => 'Prolific Author']);
        BlogPost::factory()->published()->create(['author_id' => $author->id]);

        $this->actingAs($viewer)->getJson('/api/blog/categories')->assertStatus(200);
        $this->actingAs($viewer)->getJson('/api/blog/tags')->assertStatus(200);

        $authors = $this->actingAs($viewer)->getJson('/api/blog/authors');
        $authors->assertStatus(200);
        $this->assertContains('Prolific Author', collect($authors->json('data'))->pluck('name')->all());
    }
}
