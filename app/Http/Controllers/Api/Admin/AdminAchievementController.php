<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprinkles §6.3 — awarding was left undecided by the product owner, but a
 * read-only feature nobody can populate is untestable, so admins get minimal
 * award/revoke endpoints. Automatic awarding (on debate results etc.) can be
 * layered on later without touching the read side.
 */
class AdminAchievementController extends Controller
{
    // ── POST /admin/users/{user}/achievements ───────────────────────────────────

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'rank'       => ['required', Rule::in(Achievement::RANK_ORDER)],
            'image_url'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'awarded_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $achievement = $user->achievements()->create([
            'name'       => $validated['name'],
            'rank'       => $validated['rank'],
            'image_url'  => $validated['image_url'] ?? null,
            'awarded_at' => $validated['awarded_at'] ?? now(),
        ]);

        return $this->success(
            new AchievementResource($achievement),
            'تم منح الإنجاز. | Achievement awarded.',
            201
        );
    }

    // ── DELETE /admin/users/{user}/achievements/{achievement} ───────────────────

    public function destroy(User $user, Achievement $achievement): JsonResponse
    {
        if ((int) $achievement->user_id !== (int) $user->id) {
            return $this->error('الإنجاز لا ينتمي لهذا المستخدم. | Achievement does not belong to this user.', [], 404);
        }

        $achievement->delete();

        return $this->success(null, 'تم حذف الإنجاز. | Achievement removed.');
    }
}
