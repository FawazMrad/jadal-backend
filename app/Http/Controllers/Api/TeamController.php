<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AddMembersRequest;
use App\Http\Requests\Team\CreateTeamRequest;
use App\Http\Requests\Team\ReorderPriorityRequest;
use App\Http\Requests\Team\RespondJoinRequest;
use App\Http\Requests\Team\RespondLeaveRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Http\Requests\SearchListRequest;
use App\Http\Resources\TeamJoinRequestResource;
use App\Http\Resources\TeamLeaveRequestResource;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamJoinRequest;
use App\Models\TeamLeaveRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Push\DebateNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    // ── FR-29: List trainer's own teams ───────────────────────────────────────

    public function index(SearchListRequest $request): JsonResponse
    {
        $user = $request->user();

        $query = Team::with(['leader', 'createdBy', 'teamMembers.user'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('name', 'LIKE', "%{$term}%");
            });

        $this->applyTeamFilters($query, $request);

        // Admin can see all teams
        if ($user->role === 'admin') {
            $teams = $query->latest()->get();
        }
        // Trainer can see only his own teams
        else if ($user->role === 'trainer') {
            $teams = $query->where('created_by', $user->id)
                ->latest()
                ->get();
        }
        // Debater: search/browse teams to request joining. Only active,
        // non-random teams they are NOT already a current member of — this is
        // a "find a team" list, not a management view, so teams they're
        // already on (and ad-hoc per-debate random teams, which aren't
        // something you register into) are excluded regardless of any
        // is_random/status filter passed in.
        else if ($user->role === 'debater') {
            $currentTeamIds = TeamMember::where('user_id', $user->id)
                ->where('status', 'current')
                ->pluck('team_id');

            $teams = $query->where('status', 'active')
                ->where('is_random', false)
                ->whereNotIn('id', $currentTeamIds)
                ->latest()
                ->get();
        }
        // Other roles are not allowed
        else {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بيسلان  بالوصول. | You are not authorized to access this resource.'
            ], 403);
        }

        return $this->success(
            TeamResource::collection($teams),
            'تم جلب الفرق بنجاح. | Teams retrieved.'
        );
    }

    // ── Light teams list for filter dialogs (any auth user) ──────

    /**
     * GET /teams/options?is_random=0|1&status=active|inactive&search=
     *
     * Unlike GET /teams (admin/trainer only, full member payload), this returns
     * a minimal {id, name, is_random, status} list any authenticated user can
     * read — it exists to populate the debate-search filter dialog.
     */
    public function options(SearchListRequest $request): JsonResponse
    {
        $query = Team::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('name', 'LIKE', "%{$term}%");
            });

        $this->applyTeamFilters($query, $request);

        $teams = $query->orderBy('name')->get(['id', 'name', 'is_random', 'status']);

        return $this->success(
            $teams->map(fn (Team $t) => [
                'id'        => (int) $t->id,
                'name'      => $t->name,
                'is_random' => (bool) $t->is_random,
                'status'    => $t->status,
            ])->values()->all(),
            'تم جلب خيارات الفرق. | Team options retrieved.'
        );
    }

    /**
     * Shared ?is_random= / ?status= filtering (previously these params were
     * silently ignored). Accepts is_random=0|1|true|false; status=active|inactive.
     */
    private function applyTeamFilters($query, Request $request): void
    {
        if ($request->filled('is_random')) {
            $query->where('is_random', filter_var($request->query('is_random'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('status') && in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }
    }

    // ── FR-29: Create team ────────────────────────────────────────────────────

    public function store(CreateTeamRequest $request): JsonResponse
    {
        $user = $request->user();

        // Only admin and trainer can create teams
        if (!in_array($user->role, ['admin', 'trainer'])) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بإنشاء فريق. | You are not authorized to create teams.'
            ], 403);
        }

        $leaderId  = (int) $request->leader_id;
        $memberIds = array_map('intval', $request->members);

        $team = Team::create([
            'name'       => $request->name,
            'leader_id'  => $leaderId,
            'created_by' => $user->id,           // Important: use $user->id
            'status'     => 'active',
            'is_random'  => false,
        ]);

        // Leader always gets priority 1
        TeamMember::create([
            'team_id'  => $team->id,
            'user_id'  => $leaderId,
            'priority' => 1,
            'status'   => 'current',
        ]);

        $priority = 2;
        foreach ($memberIds as $userId) {
            if ($userId === $leaderId) continue;

            TeamMember::create([
                'team_id'  => $team->id,
                'user_id'  => $userId,
                'priority' => $priority++,
                'status'   => 'current',
            ]);
        }

        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(
            new TeamResource($team),
            'تم إنشاء الفريق بنجاح. | Team created.',
            201
        );
    }

    // ── Show single team ──────────────────────────────────────────────────────

    /**
     * Single-team detail — same payload as one item of GET /teams.
     *
     * Readable by ANY authenticated user. Previously limited to
     * the trainer who created it, its leader, or a current member, which meant
     * opening a team from search showed a bare name and a "not available"
     * notice instead of the roster. The roster is the point of that screen.
     *
     * WRITE access is unaffected: ownsTeam() still guards every mutating
     * endpoint below, and the join/leave-request endpoints keep their own
     * checks. This widens WHO may read, never WHAT they may change.
     *
     * Inactive teams stay readable (there is no status filter here and none is
     * added) — read-only visibility, per the spec's stated preference.
     *
     * Contact details are the one thing that does NOT widen: for a caller with
     * no stake in the team, member/leader/coach email, phone and personal
     * fields are nulled. The KEYS and their types are unchanged, so the
     * client's existing Team parser needs no modification — only the values a
     * stranger had never been entitled to are withheld. explicitly permits
     * keeping contact details restricted.
     *
     * A non-existent id 404s via route-model binding before this runs.
     */
    public function show(Request $request, Team $team): JsonResponse
    {
        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(
            new TeamResource($team, ! $this->canViewTeam($request, $team)),
            'تم جلب الفريق. | Team retrieved.'
        );
    }

    // ── FR-29: Update team name or leader ─────────────────────────────────────

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $data = $request->validated();

        if (isset($data['leader_id'])) {
            $newLeaderId = (int) $data['leader_id'];

            // Give new leader priority 1; give old leader the new leader's previous priority
            $oldLeaderMember = TeamMember::where('team_id', $team->id)
                ->where('user_id', $team->leader_id)
                ->where('status', 'current')
                ->first();

            $newLeaderMember = TeamMember::where('team_id', $team->id)
                ->where('user_id', $newLeaderId)
                ->where('status', 'current')
                ->first();

            if ($oldLeaderMember && $newLeaderMember) {
                $swapPriority = $newLeaderMember->priority;
                $newLeaderMember->update(['priority' => 1]);
                $oldLeaderMember->update(['priority' => $swapPriority]);
            }
        }

        $team->update($data);
        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(new TeamResource($team), 'تم تحديث الفريق بنجاح. | Team updated.');
    }

    // ── FR-29: Deactivate team ────────────────────────────────────────────────

    public function destroy(Request $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $team->update(['status' => 'inactive']);

        return $this->success(null, 'تم إلغاء تفعيل الفريق. | Team deactivated.');
    }

    // ── Add member(s) ─────────────────────────────────────────────────────────

    public function addMembers(AddMembersRequest $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $memberIds   = array_map('intval', $request->member_ids);
        $addedCount  = 0;

        // Determine the next available priority for truly new members
        $maxPriority = TeamMember::where('team_id', $team->id)
            ->where('status', 'current')
            ->max('priority') ?? 0;

        foreach ($memberIds as $userId) {
            $existing = TeamMember::where('team_id', $team->id)
                ->where('user_id', $userId)
                ->first();

            if (! $existing) {
                // Brand-new member
                TeamMember::create([
                    'team_id'  => $team->id,
                    'user_id'  => $userId,
                    'priority' => ++$maxPriority,
                    'status'   => 'current',
                ]);
                $addedCount++;
            } elseif ($existing->status === 'past') {
                // Reactivate former member — FR-28 style re-join
                $existing->update([
                    'status'   => 'current',
                    'priority' => ++$maxPriority,
                ]);
                $addedCount++;
            }
            // If already 'current', skip silently
        }

        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(
            new TeamResource($team),
            "تمت إضافة {$addedCount} عضو/أعضاء. | {$addedCount} member(s) added."
        );
    }

    // ── Remove member (preserve history) ─────────────────────────────────────

    public function removeMember(Request $request, Team $team, User $user): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $membership = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'current')
            ->first();

        if (! $membership) {
            return $this->error(
                'المستخدم ليس عضواً حالياً في هذا الفريق. | User is not an active member of this team.',
                [],
                404
            );
        }

        if ($user->id === $team->leader_id) {
            return $this->error(
                'لا يمكن إزالة قائد الفريق. يرجى تغيير القائد أولاً. | Cannot remove the team leader. Change leader first.',
                [],
                422
            );
        }

        // Preserve history — do not delete
        $membership->update(['status' => 'past']);

        return $this->success(null, 'تم إزالة العضو من الفريق. | Member removed from team.');
    }

    // ── FR-27: Reorder member priorities ─────────────────────────────────────

    public function reorderPriority(ReorderPriorityRequest $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        foreach ($request->members as $item) {
            TeamMember::where('team_id', $team->id)
                ->where('user_id', $item['user_id'])
                ->where('status', 'current')
                ->update(['priority' => $item['priority']]);
        }

        // If priority 1 changed, sync the team's leader_id accordingly
        $priorityOneEntry = collect($request->members)->firstWhere('priority', 1);
        if ($priorityOneEntry) {
            $newLeaderId = (int) $priorityOneEntry['user_id'];
            if ($newLeaderId !== $team->leader_id) {
                $team->update(['leader_id' => $newLeaderId]);
            }
        }

        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(
            new TeamResource($team),
            'تم إعادة ترتيب الأولويات بنجاح. | Priorities reordered.'
        );
    }

    // ── FR-22: Request to leave team (creates pending request for trainer) ──────

    public function leave(Request $request, Team $team): JsonResponse
    {
        $user = $request->user();

        $membership = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'current')
            ->first();

        if (! $membership) {
            return $this->error(
                'أنت لست عضواً حالياً في هذا الفريق. | You are not an active member of this team.',
                [],
                404
            );
        }

        if ($user->id === $team->leader_id) {
            return $this->error(
                'القائد لا يمكنه مغادرة الفريق. يجب تعيين قائد جديد أولاً. | Team leader cannot leave. Assign a new leader first.',
                [],
                422
            );
        }

        $existing = TeamLeaveRequest::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return $this->error(
                'لديك طلب مغادرة معلق بانتظار موافقة المدرب. | You already have a pending leave request awaiting trainer approval.',
                [],
                409
            );
        }

        $leaveRequest = TeamLeaveRequest::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status'  => 'pending',
            'reason'  => $request->input('reason'),
        ]);

        // TODO: send notification to trainer ($team->createdBy)

        return $this->success(
            new TeamLeaveRequestResource($leaveRequest),
            'تم إرسال طلب المغادرة. في انتظار موافقة المدرب. | Leave request submitted. Awaiting trainer approval.'
        );
    }

    // ── Trainer: list leave requests for a team ───────────────────────────────

    public function leaveRequests(SearchListRequest $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $requests = TeamLeaveRequest::where('team_id', $team->id)
            ->with('user')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('reason', 'LIKE', "%{$term}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(
            TeamLeaveRequestResource::collection($requests),
            'تم جلب طلبات المغادرة. | Leave requests retrieved.'
        );
    }

    // ── Trainer: accept or reject a leave request ─────────────────────────────

    public function respondToLeave(RespondLeaveRequest $request, Team $team, TeamLeaveRequest $leaveRequest): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if ($leaveRequest->team_id !== $team->id) {
            return $this->error(
                'طلب المغادرة لا ينتمي لهذا الفريق. | Leave request does not belong to this team.',
                [],
                404
            );
        }

        if ($leaveRequest->status !== 'pending') {
            return $this->error(
                'تم الرد على هذا الطلب مسبقاً. | This request has already been responded to.',
                [],
                409
            );
        }

        DB::transaction(function () use ($request, $team, $leaveRequest) {
            $leaveRequest->update([
                'status'       => $request->status,
                'responded_at' => now(),
            ]);

            if ($request->status === 'accepted') {
                TeamMember::where('team_id', $team->id)
                    ->where('user_id', $leaveRequest->user_id)
                    ->where('status', 'current')
                    ->update(['status' => 'past']);

                // TODO: notify user — leave accepted
            }
            // TODO: notify user — leave rejected
        });

        $leaveRequest->load('user');

        $message = $request->status === 'accepted'
            ? 'تمت الموافقة على طلب المغادرة. | Leave request accepted.'
            : 'تم رفض طلب المغادرة. | Leave request rejected.';

        return $this->success(new TeamLeaveRequestResource($leaveRequest), $message);
    }

    // ── Debater: request to join a team (creates pending request for trainer) ──

    public function join(Request $request, Team $team): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'debater') {
            return $this->error(
                'فقط المتناظرون يمكنهم طلب الانضمام لفريق. | Only debaters can request to join a team.',
                [],
                403
            );
        }

        if ($team->is_random) {
            return $this->error(
                'لا يمكن الانضمام إلى فريق عشوائي. | Cannot register to join a random/ad-hoc team.',
                [],
                422
            );
        }

        if ($team->status !== 'active') {
            return $this->error(
                'لا يمكن الانضمام إلى فريق غير مفعل. | Cannot join an inactive team.',
                [],
                422
            );
        }

        $alreadyMember = TeamMember::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'current')
            ->exists();

        if ($alreadyMember) {
            return $this->error(
                'أنت عضو حالي بالفعل في هذا الفريق. | You are already a current member of this team.',
                [],
                409
            );
        }

        $existing = TeamJoinRequest::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return $this->error(
                'لديك طلب انضمام معلق بانتظار موافقة المدرب. | You already have a pending join request awaiting trainer approval.',
                [],
                409
            );
        }

        $joinRequest = TeamJoinRequest::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'status'  => 'pending',
            'reason'  => $request->input('reason'),
        ]);

        // TODO: send notification to trainer ($team->createdBy)

        return $this->success(
            new TeamJoinRequestResource($joinRequest),
            'تم إرسال طلب الانضمام. في انتظار موافقة المدرب. | Join request submitted. Awaiting trainer approval.'
        );
    }

    // ── Trainer: list join requests for a team ────────────────────────────────

    public function joinRequests(SearchListRequest $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $requests = TeamJoinRequest::where('team_id', $team->id)
            ->with('user')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where('reason', 'LIKE', "%{$term}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success(
            TeamJoinRequestResource::collection($requests),
            'تم جلب طلبات الانضمام. | Join requests retrieved.'
        );
    }

    // ── Trainer: accept or reject a join request ──────────────────────────────

    public function respondToJoin(RespondJoinRequest $request, Team $team, TeamJoinRequest $joinRequest): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        if ($joinRequest->team_id !== $team->id) {
            return $this->error(
                'طلب الانضمام لا ينتمي لهذا الفريق. | Join request does not belong to this team.',
                [],
                404
            );
        }

        if ($joinRequest->status !== 'pending') {
            return $this->error(
                'تم الرد على هذا الطلب مسبقاً. | This request has already been responded to.',
                [],
                409
            );
        }

        DB::transaction(function () use ($request, $team, $joinRequest) {
            $joinRequest->update([
                'status'       => $request->status,
                'responded_at' => now(),
            ]);

            if ($request->status === 'accepted') {
                // Same "brand-new vs re-activate a past row" pattern as addMembers().
                $existingMembership = TeamMember::where('team_id', $team->id)
                    ->where('user_id', $joinRequest->user_id)
                    ->first();

                $maxPriority = TeamMember::where('team_id', $team->id)
                    ->where('status', 'current')
                    ->max('priority') ?? 0;

                if ($existingMembership) {
                    $existingMembership->update(['status' => 'current', 'priority' => $maxPriority + 1]);
                } else {
                    TeamMember::create([
                        'team_id'  => $team->id,
                        'user_id'  => $joinRequest->user_id,
                        'priority' => $maxPriority + 1,
                        'status'   => 'current',
                    ]);
                }

            }
        });

        // Notify the applicant either way. Outside the
        // transaction: a push failure must never roll back the decision.
        app(DebateNotifier::class)->teamJoinResult(
            $team,
            (int) $joinRequest->user_id,
            $request->status === 'accepted'
        );

        $joinRequest->load('user');

        $message = $request->status === 'accepted'
            ? 'تمت الموافقة على طلب الانضمام. | Join request accepted.'
            : 'تم رفض طلب الانضمام. | Join request rejected.';

        return $this->success(new TeamJoinRequestResource($joinRequest), $message);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /** Write access: only the trainer who created the team. */
    private function ownsTeam(Request $request, Team $team): bool
    {
        return $team->created_by === $request->user()->id;
    }

    /**
     * Read access to a team's detail: the trainer who created it, its leader,
     * or a current member. Strictly wider than ownsTeam() — reading a roster
     * you belong to is not the same right as editing it.
     *
     * The leader is normally also a current member (store() creates that row),
     * so the leader_id check is belt-and-braces against data drift — e.g. a
     * leader whose membership row was flipped to 'past' by a bad edit would
     * still be able to see the team they lead.
     */
    private function canViewTeam(Request $request, Team $team): bool
    {
        $userId = (int) $request->user()->id;

        if ((int) $team->created_by === $userId || (int) $team->leader_id === $userId) {
            return true;
        }

        return TeamMember::where('team_id', $team->id)
            ->where('user_id', $userId)
            ->where('status', 'current')
            ->exists();
    }
}
