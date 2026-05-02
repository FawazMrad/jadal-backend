<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\RejectBlogPostRequest;
use App\Http\Requests\Blog\StoreCategoryRequest;
use App\Http\Requests\Blog\StoreTagRequest;
use App\Http\Resources\BlogPostDetailResource;
use App\Http\Resources\BlogPostResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\TagResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBlogController extends Controller
{
    // ── List all articles (any status, filterable) ────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::with(['author', 'categories', 'tags'])
            ->selectRaw('blog_posts.*,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "like") as likes_count,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "dislike") as dislikes_count
            ')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $posts = $query->paginate(15);

        return $this->paginated(
            BlogPostResource::collection($posts),
            $posts,
            'تم جلب المقالات. | Articles retrieved.'
        );
    }

    // ── Approve article ───────────────────────────────────────────────────────

    public function approve(BlogPost $post): JsonResponse
    {
        if ($post->status !== 'pending_review') {
            return $this->error(
                'يمكن الموافقة فقط على المقالات في انتظار المراجعة. | Only pending_review articles can be approved.',
                [],
                422
            );
        }

        $post->update([
            'status'       => 'published',
            'published_at' => now(),
        ]);

        $post->load(['author', 'categories', 'tags']);

        return $this->success(
            new BlogPostDetailResource($post),
            'تم نشر المقال بنجاح. | Article approved and published.'
        );
    }

    // ── Reject article ────────────────────────────────────────────────────────

    public function reject(RejectBlogPostRequest $request, BlogPost $post): JsonResponse
    {
        if ($post->status === 'rejected') {
            return $this->error('المقال مرفوض بالفعل. | Article is already rejected.', [], 422);
        }

        $post->update([
            'status'           => 'rejected',
            'reviewer_comment' => $request->reviewer_comment,
        ]);

        $post->load(['author', 'categories', 'tags']);

        return $this->success(
            new BlogPostDetailResource($post),
            'تم رفض المقال. | Article rejected.'
        );
    }

    // ── Delete any article ────────────────────────────────────────────────────

    public function destroy(BlogPost $post): JsonResponse
    {
        $post->delete();

        return $this->success(null, 'تم حذف المقال. | Article deleted.');
    }

    // ── Categories CRUD ───────────────────────────────────────────────────────

    public function listCategories(): JsonResponse
    {
        $categories = BlogCategory::orderBy('name')->get();

        return $this->success(
            CategoryResource::collection($categories),
            'تم جلب التصنيفات. | Categories retrieved.'
        );
    }

    public function storeCategory(StoreCategoryRequest $request): JsonResponse
    {
        $category = BlogCategory::create([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name, BlogCategory::class),
        ]);

        return $this->success(new CategoryResource($category), 'تم إنشاء التصنيف. | Category created.', 201);
    }

    public function updateCategory(StoreCategoryRequest $request, BlogCategory $category): JsonResponse
    {
        $category->update([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name, BlogCategory::class, $category->id),
        ]);

        return $this->success(new CategoryResource($category), 'تم تحديث التصنيف. | Category updated.');
    }

    public function destroyCategory(BlogCategory $category): JsonResponse
    {
        $category->delete();

        return $this->success(null, 'تم حذف التصنيف. | Category deleted.');
    }

    // ── Tags CRUD ─────────────────────────────────────────────────────────────

    public function listTags(): JsonResponse
    {
        $tags = BlogTag::orderBy('name')->get();

        return $this->success(TagResource::collection($tags), 'تم جلب الوسوم. | Tags retrieved.');
    }

    public function storeTag(StoreTagRequest $request): JsonResponse
    {
        $tag = BlogTag::create([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name, BlogTag::class),
        ]);

        return $this->success(new TagResource($tag), 'تم إنشاء الوسم. | Tag created.', 201);
    }

    public function updateTag(StoreTagRequest $request, BlogTag $tag): JsonResponse
    {
        $tag->update([
            'name' => $request->name,
            'slug' => $this->uniqueSlug($request->name, BlogTag::class, $tag->id),
        ]);

        return $this->success(new TagResource($tag), 'تم تحديث الوسم. | Tag updated.');
    }

    public function destroyTag(BlogTag $tag): JsonResponse
    {
        $tag->delete();

        return $this->success(null, 'تم حذف الوسم. | Tag deleted.');
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function uniqueSlug(string $name, string $model, ?int $excludeId = null): string
    {
        $slug     = Str::slug($name);
        $original = $slug;
        $i        = 2;

        while (
            $model::where('slug', $slug)
                ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $original . '-' . $i++;
        }

        return $slug;
    }
}
