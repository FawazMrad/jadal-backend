<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogCoverImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_store_accepts_an_uploaded_cover_image(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $file = UploadedFile::fake()->image('cover.jpg', 800, 400);

        $res = $this->actingAs($user)->postJson('/api/blog', [
            'title'       => 'كيف تفوز في النقاشات',
            'content'     => 'المحتوى الكامل هنا...',
            'cover_image' => $file,
        ]);

        $res->assertStatus(201);
        $post = BlogPost::first();

        $this->assertNotNull($post->cover_image_url);
        $this->assertStringStartsWith('blog-covers/', $post->cover_image_url);
        Storage::disk('public')->assertExists($post->cover_image_url);

        // The resource resolves the stored path to a full URL, not the raw path.
        $res->assertJsonPath('data.cover_image_url', Storage::disk('public')->url($post->cover_image_url));
    }

    public function test_store_without_a_cover_image_leaves_it_null(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $res = $this->actingAs($user)->postJson('/api/blog', [
            'title'   => 'Untitled',
            'content' => 'Some content.',
        ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.cover_image_url', null);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->actingAs($user)->postJson('/api/blog', [
            'title'       => 'Untitled',
            'content'     => 'Some content.',
            'cover_image' => $file,
        ])->assertStatus(422);
    }

    public function test_update_replaces_the_old_uploaded_cover_image(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        // Simulate a post that already has a previously-uploaded local cover image.
        Storage::disk('public')->put('blog-covers/existing.jpg', 'fake-bytes');
        $post = BlogPost::factory()->create([
            'author_id'       => $user->id,
            'status'          => 'draft',
            'cover_image_url' => 'blog-covers/existing.jpg',
        ]);

        $newFile = UploadedFile::fake()->image('second.jpg');
        $res = $this->actingAs($user)->putJson("/api/blog/{$post->id}", [
            'cover_image' => $newFile,
        ]);

        $res->assertStatus(200);
        $post->refresh();

        $this->assertNotNull($post->cover_image_url);
        $this->assertNotEquals('blog-covers/existing.jpg', $post->cover_image_url);
        Storage::disk('public')->assertExists($post->cover_image_url);
        Storage::disk('public')->assertMissing('blog-covers/existing.jpg');
    }
}
