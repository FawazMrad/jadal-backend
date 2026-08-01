<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AchievementResource;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Sprinkles §6.1–§6.4 — public user profiles.
 *
 * Privacy decision (§6.1 open question): email and phone are visible only to
 * the profile owner and admins; everyone else gets the public fields only.
 */
class UserProfileController extends Controller
{
    /** How many achievements ride inline on the profile itself. */
    private const TOP_ACHIEVEMENTS = 4;

    // ── §6.1: GET /users/{user} ─────────────────────────────────────────────────

    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        $isSelfOrAdmin = (int) $viewer->id === (int) $user->id || $viewer->role === 'admin';

        $top = $user->achievements()
            ->orderByRankThenRecency()
            ->limit(self::TOP_ACHIEVEMENTS)
            ->get();

        $profile = [
            'id'         => (int) $user->id,
            'name'       => $user->name,
            'role'       => $user->role,
            'status'     => $user->status,
            'avatar_url' => $user->avatar_url
                ? (str_starts_with($user->avatar_url, 'http')
                    ? $user->avatar_url
                    : Storage::disk('public')->url($user->avatar_url))
                : null,
            'points'     => (int) $user->points,
            // Computed from the stored birth_date — never stored, never stale.
            'age'        => $user->age(),
            'location'   => $user->location,
            // FE computes tenure ("how long in the debate field") from this.
            'created_at' => $user->created_at?->toIso8601String(),
            'top_achievements' => AchievementResource::collection($top),
        ];

        if ($isSelfOrAdmin) {
            $profile['email']      = $user->email;
            $profile['phone']      = $user->phone;
            $profile['birth_date'] = $user->birth_date?->toDateString();
        }

        return $this->success($profile, 'تم جلب الملف الشخصي. | Profile retrieved.');
    }

    // ── §6.3: GET /users/{user}/achievements ────────────────────────────────────

    /**
     * Spec §6.8 — the "show all" page groups by Date (default) or by Tier.
     * Server-side SORTING only; the client renders the section headers, so the
     * response shape and pagination are unchanged.
     *
     * NOTE: the default changed. This endpoint previously always returned
     * rank-then-recency; `sort=date` (the new default) is recency-first, and
     * `sort=rank` preserves the old ordering.
     */
    public function achievements(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'sort' => ['sometimes', Rule::in(['date', 'rank'])],
        ]);

        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $sort    = (string) $request->query('sort', 'date');

        $query = $user->achievements();

        if ($sort === 'rank') {
            $query->orderByRankThenRecency();
        } else {
            // Qualified: assigned_at lives on the pivot, and `achievements`
            // has its own timestamps that would otherwise be ambiguous.
            $query->orderByDesc('achievement_assignments.assigned_at');
        }

        $achievements = $query->paginate($perPage);

        return $this->paginated(
            AchievementResource::collection($achievements),
            $achievements,
            'تم جلب الإنجازات. | Achievements retrieved.'
        );
    }

    // ── §6.4: GET /users/{user}/teams ───────────────────────────────────────────

    /**
     * Current teams: memberships with status=current (role member/leader) plus
     * — for trainers — active non-random teams they coach (teams.created_by).
     */
    public function teams(Request $request, User $user): JsonResponse
    {
        $rows = [];

        $memberships = TeamMember::where('user_id', $user->id)
            ->where('status', 'current')
            ->with('team:id,name,leader_id')
            ->get();

        foreach ($memberships as $m) {
            if (! $m->team) {
                continue;
            }
            $rows[] = [
                'team_id'   => (int) $m->team_id,
                'team_name' => $m->team->name,
                'role'      => (int) $m->team->leader_id === (int) $user->id ? 'leader' : 'member',
                'joined_at' => $m->created_at?->toIso8601String(),
                'left_at'   => null,
            ];
        }

        foreach ($this->coachedTeams($user, 'active') as $team) {
            $rows[] = [
                'team_id'   => (int) $team->id,
                'team_name' => $team->name,
                'role'      => 'trainer',
                'joined_at' => $team->created_at?->toIso8601String(),
                'left_at'   => null,
            ];
        }

        return $this->success($rows, 'تم جلب الفرق الحالية. | Current teams retrieved.');
    }

    // ── §6.4: GET /users/{user}/teams/history ───────────────────────────────────

    /**
     * Past teams: memberships flipped to status=past (left/removed — rows are
     * preserved, never deleted). left_at is the row's updated_at, i.e. when the
     * membership was closed. For trainers: teams they coach that were
     * deactivated.
     */
    public function teamsHistory(Request $request, User $user): JsonResponse
    {
        $rows = [];

        $memberships = TeamMember::where('user_id', $user->id)
            ->where('status', 'past')
            ->with('team:id,name,leader_id')
            ->get();

        foreach ($memberships as $m) {
            if (! $m->team) {
                continue;
            }
            $rows[] = [
                'team_id'   => (int) $m->team_id,
                'team_name' => $m->team->name,
                'role'      => 'member',
                'joined_at' => $m->created_at?->toIso8601String(),
                'left_at'   => $m->updated_at?->toIso8601String(),
            ];
        }

        foreach ($this->coachedTeams($user, 'inactive') as $team) {
            $rows[] = [
                'team_id'   => (int) $team->id,
                'team_name' => $team->name,
                'role'      => 'trainer',
                'joined_at' => $team->created_at?->toIso8601String(),
                'left_at'   => $team->updated_at?->toIso8601String(),
            ];
        }

        return $this->success($rows, 'تم جلب سجل الفرق. | Team history retrieved.');
    }

    // ── Sprinkles §8: GET /judges — option list for the search filter dialog ────

    public function judges(): JsonResponse
    {
        $judges = User::where('role', 'judge')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_url']);

        return $this->success(
            $judges->map(fn (User $j) => [
                'id'         => (int) $j->id,
                'name'       => $j->name,
                'avatar_url' => $j->avatar_url
                    ? (str_starts_with($j->avatar_url, 'http')
                        ? $j->avatar_url
                        : Storage::disk('public')->url($j->avatar_url))
                    : null,
            ])->values()->all(),
            'تم جلب القضاة. | Judges retrieved.'
        );
    }

    /** Non-random teams this user coaches (created_by), filtered by status. */
    private function coachedTeams(User $user, string $status)
    {
        if ($user->role !== 'trainer' && $user->role !== 'admin') {
            return collect();
        }

        return Team::where('created_by', $user->id)
            ->where('is_random', false)
            ->where('status', $status)
            ->get(['id', 'name', 'created_at', 'updated_at']);
    }
}
