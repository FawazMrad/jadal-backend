<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/admin/blog filtering.
 *
 * The bug these cover: the endpoint previously supported only `status`, so the
 * dashboard filtered client-side over whichever 15 rows page 1 returned. An
 * article on page 2 was invisible to the filter even though it matched. Every
 * test below therefore pushes the matching row PAST the first page before
 * filtering for it.
 */
class AdminBlogFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    /** Creates $count posts older than anything created afterwards. */
    private function filler(int $count): void
    {
        BlogPost::factory()->count($count)->create([
            'status'     => 'published',
            'created_at' => now()->subYear(),
        ]);
    }

    private function makePost(array $attributes = []): BlogPost
    {
        return BlogPost::factory()->create($attributes + [
            'status'     => 'published',
            'created_at' => now()->subYears(2), // older than the filler → page 2+
        ]);
    }

    // ── category ──────────────────────────────────────────────────────────────

    public function test_category_filter_finds_a_match_beyond_the_first_page(): void
    {
        $category = BlogCategory::factory()->create();
        $this->filler(20);

        $target = $this->makePost(['title' => 'Buried on page two']);
        $target->categories()->attach($category->id);

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/blog?category_id[]={$category->id}")
            ->assertStatus(200)
            ->json();

        // Exactly one match, returned on page 1 of the FILTERED result set.
        $this->assertCount(1, $data['data']);
        $this->assertSame($target->id, $data['data'][0]['id']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function test_multiple_category_ids_are_ORed(): void
    {
        [$a, $b, $c] = BlogCategory::factory()->count(3)->create()->all();

        $inA = $this->makePost(); $inA->categories()->attach($a->id);
        $inB = $this->makePost(); $inB->categories()->attach($b->id);
        $inC = $this->makePost(); $inC->categories()->attach($c->id);

        $ids = collect(
            $this->actingAs($this->admin())
                ->getJson("/api/admin/blog?category_id[]={$a->id}&category_id[]={$b->id}")
                ->assertStatus(200)
                ->json('data')
        )->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $ids);
        $this->assertNotContains($inC->id, $ids);
    }

    // ── tag ───────────────────────────────────────────────────────────────────

    public function test_tag_filter_finds_a_match_beyond_the_first_page(): void
    {
        $tag = BlogTag::factory()->create();
        $this->filler(20);

        $target = $this->makePost();
        $target->tags()->attach($tag->id);

        $data = $this->actingAs($this->admin())
            ->getJson("/api/admin/blog?tag_id[]={$tag->id}")
            ->assertStatus(200)
            ->json();

        $this->assertCount(1, $data['data']);
        $this->assertSame($target->id, $data['data'][0]['id']);
        $this->assertSame(1, $data['meta']['total']);
    }

    // ── combined dimensions ───────────────────────────────────────────────────

    public function test_category_and_tag_are_ANDed_across_dimensions(): void
    {
        $category = BlogCategory::factory()->create();
        $tag      = BlogTag::factory()->create();

        $both = $this->makePost();
        $both->categories()->attach($category->id);
        $both->tags()->attach($tag->id);

        $categoryOnly = $this->makePost();
        $categoryOnly->categories()->attach($category->id);

        $tagOnly = $this->makePost();
        $tagOnly->tags()->attach($tag->id);

        $ids = collect(
            $this->actingAs($this->admin())
                ->getJson("/api/admin/blog?category_id[]={$category->id}&tag_id[]={$tag->id}")
                ->assertStatus(200)
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$both->id], $ids);
    }

    public function test_status_and_category_compose(): void
    {
        $category = BlogCategory::factory()->create();

        $draft = $this->makePost(['status' => 'draft']);
        $draft->categories()->attach($category->id);

        $published = $this->makePost(['status' => 'published']);
        $published->categories()->attach($category->id);

        $ids = collect(
            $this->actingAs($this->admin())
                ->getJson("/api/admin/blog?category_id[]={$category->id}&status=draft")
                ->assertStatus(200)
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$draft->id], $ids);
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function test_search_matches_title_or_content_beyond_the_first_page(): void
    {
        $this->filler(20);

        $byTitle   = $this->makePost(['title' => 'Unmistakable Zebra Headline', 'content' => 'nothing special']);
        $byContent = $this->makePost(['title' => 'Ordinary title', 'content' => 'buried Zebra inside the body']);

        $ids = collect(
            $this->actingAs($this->admin())
                ->getJson('/api/admin/blog?q=Zebra')
                ->assertStatus(200)
                ->json('data')
        )->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$byTitle->id, $byContent->id], $ids);
    }

    // ── publisher ─────────────────────────────────────────────────────────────

    public function test_publisher_filter_narrows_to_the_named_authors(): void
    {
        $author = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $target = $this->makePost(['author_id' => $author->id]);
        $this->makePost(); // someone else

        $ids = collect(
            $this->actingAs($this->admin())
                ->getJson("/api/admin/blog?publisher_id[]={$author->id}")
                ->assertStatus(200)
                ->json('data')
        )->pluck('id')->all();

        $this->assertSame([$target->id], $ids);
    }

    // ── pagination + validation ───────────────────────────────────────────────

    public function test_per_page_is_honoured_and_capped(): void
    {
        $this->filler(30);
        $admin = $this->admin();

        // Default
        $this->assertCount(15, $this->actingAs($admin)->getJson('/api/admin/blog')->json('data'));

        // Honoured
        $this->assertCount(5, $this->actingAs($admin)->getJson('/api/admin/blog?per_page=5')->json('data'));

        // Above the cap is a validation error, matching the public endpoint.
        $this->actingAs($admin)->getJson('/api/admin/blog?per_page=1000')->assertStatus(422);
    }

    public function test_meta_reflects_the_filtered_total_not_the_table_total(): void
    {
        $category = BlogCategory::factory()->create();
        $this->filler(20);

        $target = $this->makePost();
        $target->categories()->attach($category->id);

        // 21 rows exist; only 1 matches. `total` must be the filtered count, or
        // the dashboard renders pagination controls for pages that do not exist.
        $this->actingAs($this->admin())
            ->getJson("/api/admin/blog?category_id[]={$category->id}")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/blog?status=not_a_status')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_unfiltered_listing_still_returns_every_status(): void
    {
        // Regression guard: adding filters must not accidentally scope the
        // admin list to published, which is the public endpoint's behaviour.
        foreach (['draft', 'pending_review', 'published', 'rejected'] as $status) {
            $this->makePost(['status' => $status]);
        }

        $statuses = collect(
            $this->actingAs($this->admin())
                ->getJson('/api/admin/blog')
                ->assertStatus(200)
                ->json('data')
        )->pluck('status')->unique()->values()->all();

        $this->assertEqualsCanonicalizing(
            ['draft', 'pending_review', 'published', 'rejected'],
            $statuses
        );
    }
}
