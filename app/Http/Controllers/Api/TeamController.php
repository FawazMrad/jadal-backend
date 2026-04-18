<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AddMembersRequest;
use App\Http\Requests\Team\CreateTeamRequest;
use App\Http\Requests\Team\ReorderPriorityRequest;
use App\Http\Requests\Team\RespondLeaveRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Http\Resources\TeamLeaveRequestResource;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamLeaveRequest;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    // ── FR-29: List trainer's own teams ───────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $teams = Team::where('created_by', $request->user()->id)
            ->with(['leader', 'createdBy', 'teamMembers.user'])
            ->latest()
            ->get();

        return $this->success(
            TeamResource::collection($teams),
            'تم جلب الفرق بنجاح. | Teams retrieved.'
        );
    }

    // ── FR-29: Create team ────────────────────────────────────────────────────

    public function store(CreateTeamRequest $request): JsonResponse
    {
        $leaderId  = (int) $request->leader_id;
        $memberIds = array_map('intval', $request->members);

        $team = Team::create([
            'name'       => $request->name,
            'leader_id'  => $leaderId,
            'created_by' => $request->user()->id,
            'status'     => 'active',
            'is_random'  => false,
        ]);

        // Leader always gets priority 1; remaining members in provided order get 2, 3, ...
        TeamMember::create([
            'team_id'  => $team->id,
            'user_id'  => $leaderId,
            'priority' => 1,
            'status'   => 'current',
        ]);

        $priority = 2;
        foreach ($memberIds as $userId) {
            if ($userId === $leaderId) {
                continue;
            }
            TeamMember::create([
                'team_id'  => $team->id,
                'user_id'  => $userId,
                'priority' => $priority++,
                'status'   => 'current',
            ]);
        }

        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(new TeamResource($team), 'تم إنشاء الفريق بنجاح. | Team created.', 201);
    }

    // ── Show single team ──────────────────────────────────────────────────────

    public function show(Request $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $team->load(['leader', 'createdBy', 'teamMembers.user']);

        return $this->success(new TeamResource($team), 'تم جلب بيانات الفريق. | Team retrieved.');
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

    public function leaveRequests(Request $request, Team $team): JsonResponse
    {
        if (! $this->ownsTeam($request, $team)) {
            return $this->error('غير مصرح. | Unauthorized.', [], 403);
        }

        $requests = TeamLeaveRequest::where('team_id', $team->id)
            ->with('user')
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

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function ownsTeam(Request $request, Team $team): bool
    {
        return $team->created_by === $request->user()->id;
    }
}
