<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\ReactToBlogPostRequest;
use App\Http\Requests\Blog\StoreBlogPostRequest;
use App\Http\Requests\Blog\UpdateBlogPostRequest;
use App\Http\Resources\BlogPostDetailResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ── List published articles (paginated, filterable) ───────────────────────

    /**
     * The list endpoint doubles as the blog search: `q` matches
     * title OR content; category_id[]/tag_id[]/publisher_id[] are multi-select
     * (OR within a dimension, AND across dimensions); liked_by_me=true scopes
     * to the caller's own like reactions. The legacy slug-based ?category= and
     * ?tag= params keep working unchanged.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'               => ['sometimes', 'nullable', 'string', 'max:200'],
            'category_id'     => ['sometimes', 'array'],
            'category_id.*'   => ['integer'],
            'tag_id'          => ['sometimes', 'array'],
            'tag_id.*'        => ['integer'],
            'publisher_id'    => ['sometimes', 'array'],
            'publisher_id.*'  => ['integer'],
            'liked_by_me'     => ['sometimes', 'boolean'],
            'per_page'        => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BlogPost::with(['author', 'categories', 'tags'])
            ->selectRaw('blog_posts.*,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "like") as likes_count,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "dislike") as dislikes_count
            ')
            ->where('status', 'published')
            ->orderBy('published_at', 'desc');

        // Legacy slug-based filters (kept as-is).
        if ($request->filled('category')) {
            $query->whereHas('categories', fn($q) => $q->where('slug', $request->category));
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn($q) => $q->where('slug', $request->tag));
        }

        if (! empty($validated['q'])) {
            $term = $validated['q'];
            $query->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('content', 'LIKE', "%{$term}%");
            });
        }
        if (! empty($validated['category_id'])) {
            $ids = $validated['category_id'];
            $query->whereHas('categories', fn ($q) => $q->whereIn('blog_categories.id', $ids));
        }
        if (! empty($validated['tag_id'])) {
            $ids = $validated['tag_id'];
            $query->whereHas('tags', fn ($q) => $q->whereIn('blog_tags.id', $ids));
        }
        if (! empty($validated['publisher_id'])) {
            $query->whereIn('author_id', $validated['publisher_id']);
        }
        if ($request->boolean('liked_by_me')) {
            $userId = $request->user()->id;
            $query->whereExists(function ($q) use ($userId) {
                $q->selectRaw('1')
                  ->from('blog_post_reactions')
                  ->whereColumn('blog_post_reactions.post_id', 'blog_posts.id')
                  ->where('blog_post_reactions.user_id', $userId)
                  ->where('blog_post_reactions.type', 'like');
            });
        }

        $posts = $query->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginated(
            BlogPostResource::collection($posts),
            $posts,
            'تم جلب المقالات بنجاح. | Articles retrieved.'
        );
    }

    /**
     * GET /blog/authors: users who have at least one published
     * post, for the publisher filter's option picker.
     */
    public function authors(): JsonResponse
    {
        $authors = User::whereHas('blogPosts', fn ($q) => $q->where('status', 'published'))
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_url']);

        return $this->success(
            $authors->map(fn ($a) => [
                'id'         => (int) $a->id,
                'name'       => $a->name,
                'avatar_url' => $a->avatar_url
                    ? (str_starts_with($a->avatar_url, 'http')
                        ? $a->avatar_url
                        : Storage::disk('public')->url($a->avatar_url))
                    : null,
            ])->values()->all(),
            'تم جلب الكتّاب. | Authors retrieved.'
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
