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
use Illuminate\Validation\Rule;

class AdminBlogController extends Controller
{
    /** Mirrors the blog_posts.status enum (2025_01_01_000017_create_blog_posts_table). */
    private const STATUSES = ['draft', 'pending_review', 'published', 'rejected'];

    // ── List all articles (any status, filterable) ────────────────────────────

    /**
     * Admin article list.
     *
     * Filtering is applied to the QUERY, before pagination — the dashboard must
     * never filter the current page client-side. Doing so silently searches only
     * the 15 rows that happened to load, so an article on page 2 looks like it
     * does not exist.
     *
     * The filter contract deliberately mirrors the public BlogController::index
     * (`q`, `category_id[]`, `tag_id[]`, `publisher_id[]`, `per_page`) so one
     * client-side query builder serves both screens. Two intentional
     * differences remain:
     *   - `status` is admin-only; the public endpoint is hard-scoped to
     *     `published`.
     *   - ordering is by `created_at`, not `published_at`: drafts and
     *     pending_review articles have no `published_at`, so ordering by it
     *     would bury exactly the rows an admin opens this screen to review.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'              => ['sometimes', 'nullable', 'string', 'max:200'],
            'status'         => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'category_id'    => ['sometimes', 'array'],
            'category_id.*'  => ['integer'],
            'tag_id'         => ['sometimes', 'array'],
            'tag_id.*'       => ['integer'],
            'publisher_id'   => ['sometimes', 'array'],
            'publisher_id.*' => ['integer'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BlogPost::with(['author', 'categories', 'tags'])
            ->selectRaw('blog_posts.*,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "like") as likes_count,
                (SELECT COUNT(*) FROM blog_post_reactions WHERE post_id = blog_posts.id AND type = "dislike") as dislikes_count
            ')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if (! empty($validated['q'])) {
            $term = $validated['q'];
            $query->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('content', 'LIKE', "%{$term}%");
            });
        }

        // Multi-select filters are OR *within* a dimension and AND *across*
        // dimensions: ?category_id[]=1&category_id[]=2&tag_id[]=5 means
        // "(category 1 OR 2) AND tag 5". Same semantics as the public endpoint.
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

        $posts = $query->paginate((int) ($validated['per_page'] ?? 15));

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
