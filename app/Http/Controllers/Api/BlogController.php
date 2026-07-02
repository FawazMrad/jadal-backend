<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\ReactToBlogPostRequest;
use App\Http\Requests\Blog\StoreBlogPostRequest;
use App\Http\Requests\Blog\UpdateBlogPostRequest;
use App\Http\Resources\BlogPostDetailResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ── List published articles (paginated, filterable) ───────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::with(['author', 'categories', 'tags'])
            ->selectRaw('blog_posts.*,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "like") as likes_count,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "dislike") as dislikes_count
            ')
            ->where('status', 'published')
            ->orderBy('published_at', 'desc');

        if ($request->filled('category')) {
            $query->whereHas('categories', fn($q) => $q->where('slug', $request->category));
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('slug', $request->tag));
        }

        $posts = $query->paginate(15);

        return $this->paginated(
            BlogPostResource::collection($posts),
            $posts,
            'تم جلب المقالات بنجاح. | Articles retrieved.'
        );
    }

    // ── View single published article (increments views) ─────────────────────

    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::with(['author', 'categories', 'tags'])
            ->selectRaw('blog_posts.*,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "like") as likes_count,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "dislike") as dislikes_count
            ')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $post->increment('views');
        $post->views = ($post->views ?? 0) + 1;

        return $this->success(
            new BlogPostDetailResource($post),
            'تم جلب المقال بنجاح. | Article retrieved.'
        );
    }

    // ── Like / dislike with toggle ────────────────────────────────────────────

    public function react(ReactToBlogPostRequest $request, BlogPost $post): JsonResponse
    {
        if ($post->status !== 'published') {
            return $this->error('المقال غير متاح. | Article not available.', [], 404);
        }

        $userId = $request->user()->id;
        $type   = $request->type;

        $existing = DB::table('blog_post_reactions')
            ->where('post_id', $post->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->type === $type) {
                DB::table('blog_post_reactions')->where('id', $existing->id)->delete();
                $message = 'تم إلغاء التفاعل. | Reaction removed.';
            } else {
                DB::table('blog_post_reactions')->where('id', $existing->id)->update(['type' => $type]);
                $message = 'تم تغيير التفاعل. | Reaction updated.';
            }
        } else {
            DB::table('blog_post_reactions')->insert([
                'post_id'    => $post->id,
                'user_id'    => $userId,
                'type'       => $type,
                'created_at' => now(),
            ]);
            $message = 'تم إضافة التفاعل. | Reaction added.';
        }

        $likes    = DB::table('blog_post_reactions')->where('post_id', $post->id)->where('type', 'like')->count();
        $dislikes = DB::table('blog_post_reactions')->where('post_id', $post->id)->where('type', 'dislike')->count();

        return $this->success(
            ['likes_count' => $likes, 'dislikes_count' => $dislikes],
            $message
        );
    }

    // ── Submit new article (any user, auto pending_review) ───────────────────

    public function store(StoreBlogPostRequest $request): JsonResponse
    {
        $data = $request->validated();

        $post = BlogPost::create([
            'author_id'       => $request->user()->id,
            'title'           => $data['title'],
            'slug'            => $this->generateSlug($data['title']),
            'content'         => $data['content'],
            'cover_image_url' => $request->hasFile('cover_image')
                ? $this->storeCoverImage($request->file('cover_image'))
                : null,
            'status'          => 'pending_review',
        ]);

        if (! empty($data['category_ids'])) {
            $post->categories()->sync($data['category_ids']);
        }
        if (! empty($data['tag_ids'])) {
            $post->tags()->sync($data['tag_ids']);
        }

        $post->load(['author', 'categories', 'tags']);

        return $this->success(
            new BlogPostDetailResource($post),
            'تم إرسال المقال للمراجعة. | Article submitted for review.',
            201
        );
    }

    // ── Edit own article (draft or rejected only) ─────────────────────────────

    public function update(UpdateBlogPostRequest $request, BlogPost $post): JsonResponse
    {
        if ($post->author_id !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if (! in_array($post->status, ['draft', 'rejected'])) {
            return $this->error(
                'لا يمكن تعديل المقال في حالته الحالية. | Article cannot be edited with its current status.',
                [],
                422
            );
        }

        $data       = $request->validated();
        $updateData = [];

        if (array_key_exists('title', $data)) {
            $updateData['title'] = $data['title'];
            $updateData['slug']  = $this->generateSlug($data['title'], $post->id);
        }
        if (array_key_exists('content', $data)) {
            $updateData['content'] = $data['content'];
        }
        if ($request->hasFile('cover_image')) {
            $this->deleteCoverImageIfLocal($post->cover_image_url);
            $updateData['cover_image_url'] = $this->storeCoverImage($request->file('cover_image'));
        }

        if (! empty($updateData)) {
            $post->update($updateData);
        }

        if (array_key_exists('category_ids', $data)) {
            $post->categories()->sync($data['category_ids'] ?? []);
        }
        if (array_key_exists('tag_ids', $data)) {
            $post->tags()->sync($data['tag_ids'] ?? []);
        }

        $post->load(['author', 'categories', 'tags']);

        return $this->success(
            new BlogPostDetailResource($post),
            'تم تحديث المقال بنجاح. | Article updated.'
        );
    }

    // ── Delete own article (draft or rejected only) ───────────────────────────

    public function destroy(Request $request, BlogPost $post): JsonResponse
    {
        if ($post->author_id !== $request->user()->id) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if (! in_array($post->status, ['draft', 'rejected'])) {
            return $this->error(
                'لا يمكن حذف المقال في حالته الحالية. | Article cannot be deleted with its current status.',
                [],
                422
            );
        }

        $post->delete();

        return $this->success(null, 'تم حذف المقال بنجاح. | Article deleted.');
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Store an uploaded cover image under storage/app/public/blog-covers/{uuid}.ext
     * and return the relative path (same pattern as ProfileController::uploadAvatar).
     */
    private function storeCoverImage(UploadedFile $file): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('blog-covers', $file, $filename);

        return 'blog-covers/' . $filename;
    }

    /**
     * Delete a stored cover image file, but never an external URL (legacy rows
     * created before uploads existed may still hold a raw http(s) link).
     */
    private function deleteCoverImageIfLocal(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function generateSlug(string $title, ?int $excludeId = null): string
    {
        $slug     = Str::slug($title);
        $original = $slug;
        $i        = 2;

        while (
            BlogPost::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $original . '-' . $i++;
        }

        return $slug;
    }
}
