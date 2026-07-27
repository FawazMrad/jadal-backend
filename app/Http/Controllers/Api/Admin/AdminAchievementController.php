<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Achievement\AssignAchievementRequest;
use App\Http\Requests\Achievement\StoreAchievementRequest;
use App\Http\Requests\Achievement\UpdateAchievementRequest;
use App\Http\Resources\AchievementAssignmentResource;
use App\Http\Resources\AchievementCatalogResource;
use App\Models\Achievement;
use App\Models\AchievementAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Achievement feature redesign: a shared catalog (this controller's
 * index/store/show/update/destroy) plus per-user assignments
 * (assign/revoke/available). Frontend groups the catalog by type/date itself
 * (a small reference list) — this API just returns it flat and sorted.
 */
class AdminAchievementController extends Controller
{
    // ── Catalog CRUD ──────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $achievements = Achievement::withCount('assignments')
            ->orderByDesc('created_at')
            ->get();

        return $this->success(
            AchievementCatalogResource::collection($achievements),
            'تم جلب الإنجازات. | Achievements retrieved.'
        );
    }

    public function store(StoreAchievementRequest $request): JsonResponse
    {
        $achievement = Achievement::create([
            'name'      => $request->validated('name'),
            'type'      => $request->validated('type'),
            'image_url' => $request->hasFile('image') ? $this->storeImage($request->file('image')) : null,
        ]);

        return $this->success(
            new AchievementCatalogResource($achievement->loadCount('assignments')),
            'تم إنشاء الإنجاز. | Achievement created.',
            201
        );
    }

    public function show(Achievement $achievement): JsonResponse
    {
        return $this->success(
            new AchievementCatalogResource($achievement->loadCount('assignments')),
            'تم جلب الإنجاز. | Achievement retrieved.'
        );
    }

    public function update(UpdateAchievementRequest $request, Achievement $achievement): JsonResponse
    {
        $data = $request->validated();
        $updateData = [];

        if (array_key_exists('name', $data)) {
            $updateData['name'] = $data['name'];
        }
        if (array_key_exists('type', $data)) {
            $updateData['type'] = $data['type'];
        }
        if ($request->hasFile('image')) {
            // image = an actual file -> add/replace it.
            $this->deleteImageIfLocal($achievement->image_url);
            $updateData['image_url'] = $this->storeImage($request->file('image'));
        } elseif ($request->has('image') && $request->input('image') === null) {
            // image = "" (normalized to null by ConvertEmptyStringsToNull) -> remove it.
            $this->deleteImageIfLocal($achievement->image_url);
            $updateData['image_url'] = null;
        }
        // image key absent entirely -> leave the current image untouched.

        if (! empty($updateData)) {
            $achievement->update($updateData);
        }

        return $this->success(
            new AchievementCatalogResource($achievement->fresh()->loadCount('assignments')),
            'تم تحديث الإنجاز. | Achievement updated.'
        );
    }

    /**
     * Blocks deletion while the achievement is still assigned to anyone,
     * unless `force=true` is passed — in which case the assignments are
     * removed first (inside the same transaction the FK's restrictOnDelete
     * would otherwise reject).
     */
    public function destroy(Request $request, Achievement $achievement): JsonResponse
    {
        $assignedCount = $achievement->assignments()->count();

        if ($assignedCount > 0 && ! $request->boolean('force')) {
            return $this->error(
                "لا يمكن حذف هذا الإنجاز لأنه ممنوح لـ {$assignedCount} مستخدم. أرسل force=true للحذف مع إزالة كل المنح. | Cannot delete — this achievement is currently assigned to {$assignedCount} user(s). Pass force=true to delete it along with all assignments.",
                ['assigned_count' => $assignedCount],
                409
            );
        }

        DB::transaction(function () use ($achievement) {
            $achievement->assignments()->delete();
            $achievement->delete();
        });

        return $this->success(null, 'تم حذف الإنجاز. | Achievement deleted.');
    }

    // ── Per-user assignment ─────────────────────────────────────────────────────

    public function assign(AssignAchievementRequest $request, User $user): JsonResponse
    {
        $achievementId = (int) $request->validated('achievement_id');

        $alreadyHas = AchievementAssignment::where('user_id', $user->id)
            ->where('achievement_id', $achievementId)
            ->exists();

        if ($alreadyHas) {
            return $this->error('هذا المستخدم حصل على هذا الإنجاز مسبقاً. | User already has this achievement.', [], 409);
        }

        $assignment = AchievementAssignment::create([
            'user_id'        => $user->id,
            'achievement_id' => $achievementId,
            'assigned_at'    => now(),
            'assigned_by'    => $request->user()->id,
        ]);

        $assignment->load(['achievement', 'assignedBy']);

        return $this->success(
            new AchievementAssignmentResource($assignment),
            'تم منح الإنجاز. | Achievement awarded.',
            201
        );
    }

    public function revoke(User $user, Achievement $achievement): JsonResponse
    {
        $deleted = AchievementAssignment::where('user_id', $user->id)
            ->where('achievement_id', $achievement->id)
            ->delete();

        if ($deleted === 0) {
            return $this->error('هذا المستخدم لا يملك هذا الإنجاز. | User does not have this achievement.', [], 404);
        }

        return $this->success(null, 'تم سحب الإنجاز. | Achievement revoked.');
    }

    /** Catalog achievements NOT yet assigned to this user — feeds the assignment modal. */
    public function available(User $user): JsonResponse
    {
        $assignedIds = AchievementAssignment::where('user_id', $user->id)->pluck('achievement_id');

        $available = Achievement::whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->get();

        return $this->success(
            AchievementCatalogResource::collection($available),
            'تم جلب الإنجازات المتاحة. | Available achievements retrieved.'
        );
    }

    // ── Image storage (mirrors ProfileController::uploadAvatar / BlogController::storeCoverImage) ──

    private function storeImage(UploadedFile $file): string
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        Storage::disk('public')->putFileAs('achievements', $file, $filename);

        return 'achievements/' . $filename;
    }

    private function deleteImageIfLocal(?string $path): void
    {
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }
}
