<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\AssignParticipantsRequest;
use App\Http\Requests\Debate\LinkDebateTeamsRequest;
use App\Http\Requests\SearchListRequest;
use App\Http\Requests\Debate\StoreDebateRequest;
use App\Http\Requests\Debate\UpdateDebateRequest;
use App\Http\Requests\Debate\UpdateParticipantStatusRequest;
use App\Http\Resources\DebateDetailResource;
use App\Http\Resources\DebateParticipantResource;
use App\Http\Resources\DebateResource;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminDebateController extends Controller
{
    public function index(SearchListRequest $request): JsonResponse
    {
        $debates = Debate::with(['format', 'motion'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->tag, fn ($q) => $q->where('tag', $request->tag))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->input('search');
                $q->where(function ($inner) use ($term) {
                    $inner->where('title', 'LIKE', "%{$term}%")
                          ->orWhere('tag', 'LIKE', "%{$term}%")
                          ->orWhereHas('motion', fn ($m) => $m->where('text', 'LIKE', "%{$term}%"));
                });
            })
            ->latest()
            ->paginate(20);

        return $this->paginated(DebateResource::collection($debates), $debates, 'تم جلب النقاشات. | Debates retrieved.');
    }

    public function store(StoreDebateRequest $request): JsonResponse
    {
        $debate = Debate::create(array_merge($request->validated(), [
            'created_by'        => $request->user()->id,
            'status'            => 'scheduled',
            'livekit_room_name' => 'debate-' . Str::uuid(),
        ]));

        $debate->load(['format', 'motion', 'createdBy']);

        return $this->success(new DebateDetailResource($debate), 'تم إنشاء النقاش. | Debate created.', 201);
    }

    public function show(Debate $debate): JsonResponse
    {
        // feedbacks() relationship now correctly points to Feedbacks::class.
        $debate->load(['format', 'motion', 'createdBy', 'participants.user', 'participants.team', 'phases', 'result.judge', 'feedbacks.fromUser', 'feedbacks.toUser']);

        return $this->success(new DebateDetailResource($debate), 'تم جلب النقاش. | Debate retrieved.');
    }

    public function update(UpdateDebateRequest $request, Debate $debate): JsonResponse
    {
        $debate->update($request->validated());
        $debate->load(['format', 'motion']);

        return $this->success(new DebateResource($debate), 'تم تحديث النقاش. | Debate updated.');
    }

    public function assignParticipants(AssignParticipantsRequest $request, Debate $debate): JsonResponse
    {
        DB::transaction(function () use ($request, $debate) {
            // Judges missing an explicit judge_order get the next monotonic value.
            $nextJudgeOrder = $this->nextJudgeOrder($request->participants, $debate);

            // Each entry is applied independently to its own (debate_id, user_id)
            // row. No row is touched unless it is explicitly listed in the payload.
            foreach ($request->participants as $p) {
                $judgeOrder = null;
                if (($p['role'] ?? null) === 'judge') {
                    $judgeOrder = isset($p['judge_order'])
                        ? (int) $p['judge_order']
                        : $nextJudgeOrder++;
                }

                $attrs = [
                    'team_id'              => $p['team_id'] ?? null,
                    'role'                 => $p['role'],
                    'side'                 => $p['side'],
                    'status'               => 'approved',
                    'is_chair'             => $p['is_chair'] ?? false,
                    'is_attended'          => false,
                    'speaking_phase_order' => null,
                    'judge_order'          => $judgeOrder,
                ];

                DebateParticipant::updateOrCreate(
                    ['debate_id' => $debate->id, 'user_id' => $p['user_id']],
                    $attrs
                );
            }

            // Admin-driven status bump: once both sides have an approved debater and
            // there is at least one approved judge, the debate is "announced".
            $hasPropDebaters  = $debate->participants()->where('side', 'proposition')->where('role', 'debater')->where('status', 'approved')->exists();
            $hasOppDebaters   = $debate->participants()->where('side', 'opposition')->where('role', 'debater')->where('status', 'approved')->exists();
            $hasApprovedJudge = $debate->participants()->where('role', 'judge')->where('status', 'approved')->exists();

            if ($debate->status === 'scheduled' && $hasPropDebaters && $hasOppDebaters && $hasApprovedJudge) {
                $debate->status = 'announced';
                $debate->save();
            }
        });

        $debate->load('participants.user');

        return $this->success(
            DebateParticipantResource::collection($debate->participants),
            'تم تعيين المشاركين. | Participants assigned.'
        );
    }

    /**
     * POST /admin/debates/{debate}/teams
     *
     * Admin pre-declares which team plays each side, before roster selection.
     * Side is then known in advance so the team's self-roster endpoint can
     * auto-assign it.
     */
    public function linkTeams(LinkDebateTeamsRequest $request, Debate $debate): JsonResponse
    {
        $propId = (int) $request->proposition_team_id;
        $oppId  = (int) $request->opposition_team_id;

        // Both teams must be active.
        $teams = Team::whereIn('id', [$propId, $oppId])->get()->keyBy('id');
        foreach ([$propId, $oppId] as $teamId) {
            if (($teams[$teamId]->status ?? null) !== 'active') {
                return $this->error('يجب أن يكون الفريق نشطاً. | Both teams must be active.', [], 422);
            }
        }

        // Neither submitted team may already be linked to this debate.
        $alreadyLinked = array_filter([
            (int) $debate->proposition_team_id,
            (int) $debate->opposition_team_id,
        ]);
        if (! empty(array_intersect([$propId, $oppId], $alreadyLinked))) {
            return $this->error('الفريق مرتبط بالفعل بهذا النقاش. | A team is already linked to this debate.', [], 422);
        }

        $debate->update([
            'proposition_team_id' => $propId,
            'opposition_team_id'  => $oppId,
        ]);

        return $this->success([
            'debate_id'           => $debate->id,
            'proposition_team_id' => $propId,
            'opposition_team_id'  => $oppId,
        ], 'تم ربط الفرق بالنقاش. | Teams linked to debate sides.');
    }

    /**
     * V12 §2: POST /admin/debates/{debate}/announce
     *
     * The admin selects the line-up: ≥1 judge and the TWO teams that will debate —
     * each team being either an existing team (`team_id`) or a "random" team (a
     * collection of solo applicants, `user_ids`, which we materialise into an
     * is_random team). Sides (prop/opp) and speaker order are NOT chosen here.
     * On success the debate flips scheduled → announced. The later
     * announced → teams-selected transition is driven by the time offset
     * (AdvanceDebatesLifecycle) once both sides have an approved roster.
     */
    public function announce(Request $request, Debate $debate): JsonResponse
    {
        $validated = $request->validate([
            'judges'             => ['required', 'array', 'min:1'],
            'judges.*'           => ['integer', 'distinct', 'exists:users,id'],
            'teams'              => ['required', 'array', 'size:2'],
            'teams.*.team_id'    => ['nullable', 'integer', 'exists:teams,id'],
            'teams.*.user_ids'   => ['nullable', 'array', 'min:' . DebateFormat::SPEAKERS_PER_SIDE],
            'teams.*.user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        if ($debate->status !== 'scheduled') {
            return $this->error('يمكن إعلان النقاشات المجدولة فقط. | Only scheduled debates can be announced.', [], 422);
        }

        // Each team entry must be EXACTLY one of team_id | user_ids.
        foreach ($validated['teams'] as $i => $t) {
            if (! empty($t['team_id']) === ! empty($t['user_ids'])) {
                return $this->error(
                    'كل فريق يجب أن يكون فريقاً موجوداً أو مجموعة لاعبين، وليس كليهما. | Each team must be exactly one of team_id or user_ids.',
                    [], 422
                );
            }
            // Existing teams must be able to field a full line-up.
            if (! empty($t['team_id'])) {
                $count = TeamMember::where('team_id', $t['team_id'])->where('status', 'current')->count();
                if ($count < DebateFormat::SPEAKERS_PER_SIDE) {
                    return $this->error(
                        "الفريق رقم " . ($i + 1) . " لا يملك عدداً كافياً من الأعضاء. | Team " . ($i + 1) . " has too few members.",
                        [], 422
                    );
                }
            }
        }

        DB::transaction(function () use ($validated, $debate, $request) {
            // 1) Resolve each entry to a concrete team_id (creating random teams).
            $teamIds = [];
            foreach ($validated['teams'] as $t) {
                $teamIds[] = ! empty($t['team_id'])
                    ? (int) $t['team_id']
                    : $this->materialiseRandomTeam($t['user_ids'], (int) $request->user()->id);
            }

            // 2) Two neutral slots — the FE renders them as first/second team while
            //    announced; the prop/opp meaning only applies from teams-selected on.
            $debate->update([
                'proposition_team_id' => $teamIds[0],
                'opposition_team_id'  => $teamIds[1],
            ]);

            // 3) Each team's current members become this debate's debater pool,
            //    tagged with the team — side stays NULL (assigned later via roster).
            $keptUserIds = array_map('intval', $validated['judges']);
            foreach ($teamIds as $teamId) {
                $memberIds = TeamMember::where('team_id', $teamId)->where('status', 'current')->pluck('user_id');
                foreach ($memberIds as $uid) {
                    $keptUserIds[] = (int) $uid;
                    DebateParticipant::updateOrCreate(
                        ['debate_id' => $debate->id, 'user_id' => (int) $uid],
                        ['team_id' => $teamId, 'role' => 'debater', 'side' => null, 'status' => 'pending', 'is_chair' => false]
                    );
                }
            }

            // 4) Approve the judges, monotonic order, chair = lowest order.
            $order = 1;
            foreach ($validated['judges'] as $uid) {
                DebateParticipant::updateOrCreate(
                    ['debate_id' => $debate->id, 'user_id' => (int) $uid],
                    [
                        'team_id'     => null,
                        'role'        => 'judge',
                        'side'        => 'judge',
                        'status'      => 'approved',
                        'judge_order' => $order,
                        'is_chair'    => $order === 1,
                    ]
                );
                $order++;
            }

            // 5) Anyone who registered but wasn't picked (a different team, a
            //    solo debater not folded into a random team, an unlisted judge)
            //    is left over as a stale 'pending' row from register() — reject
            //    them now so they don't linger as an ambiguous participant.
            DebateParticipant::where('debate_id', $debate->id)
                ->where('status', 'pending')
                ->whereNotIn('user_id', $keptUserIds)
                ->update(['status' => 'rejected']);

            $debate->update(['status' => 'announced']);
        });

        $debate->load('participants.user');

        return $this->success(
            new DebateDetailResource($debate->fresh(['format', 'motion', 'createdBy', 'participants.user'])),
            'تم إعلان النقاش. | Debate announced.'
        );
    }

    /**
     * Materialise a "random" team from a collection of solo applicants into an
     * is_random team so it can occupy a debate slot like any other team.
     */
    private function materialiseRandomTeam(array $userIds, int $adminId): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        // Sequential, zero-padded: Random-00001, Random-00002, ...
        $sequence = Team::where('is_random', true)->count() + 1;

        $team = Team::create([
            'name'       => 'Random-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'leader_id'  => $userIds[0],
            'created_by' => $adminId,
            'is_random'  => true,
            'status'     => 'active',
        ]);

        foreach ($userIds as $idx => $uid) {
            TeamMember::create([
                'team_id'  => $team->id,
                'user_id'  => $uid,
                'priority' => $idx + 1,
                'status'   => 'current',
            ]);
        }

        return (int) $team->id;
    }

    /**
     * GET /admin/debates/{debate}/teams/{team}/pending-participants
     *
     * Returns the pending debate_participants rows that registration already
     * created for this debate + team (correctly role-tagged debater/trainer).
     * The admin UI uses this as a checklist, then submits only the chosen
     * user_ids to assignParticipants — no auto-pull/guessing on the server.
     */
    public function pendingParticipants(Debate $debate, Team $team): JsonResponse
    {
        $participants = DebateParticipant::where('debate_id', $debate->id)
            ->where('team_id', $team->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        return $this->success(
            DebateParticipantResource::collection($participants),
            'تم جلب المشاركين المعلقين للفريق. | Pending team participants retrieved.'
        );
    }

    /**
     * Highest existing judge_order across the incoming payload and the debate's
     * already-assigned judges, plus one. Keeps ordering monotonic when some
     * judges omit an explicit order.
     */
    private function nextJudgeOrder(array $participants, Debate $debate): int
    {
        $payloadMax = collect($participants)
            ->filter(fn ($p) => ($p['role'] ?? null) === 'judge' && isset($p['judge_order']))
            ->max('judge_order') ?? 0;

        $existingMax = (int) $debate->participants()
            ->where('role', 'judge')
            ->max('judge_order');

        return max((int) $payloadMax, $existingMax) + 1;
    }

    public function updateParticipantStatus(
        UpdateParticipantStatusRequest $request,
        Debate $debate,
        DebateParticipant $participant
    ): JsonResponse {
        if ($participant->debate_id !== $debate->id) {
            return $this->error('المشارك لا ينتمي لهذا النقاش. | Participant not in this debate.', [], 404);
        }

        $participant->update(['status' => $request->status]);

        return $this->success(
            new DebateParticipantResource($participant->load('user')),
            'تم تحديث حالة المشارك. | Participant status updated.'
        );
    }

    // ── F8: POST /admin/debates/{debate}/judges/order ─────────────────────────

    public function setJudgesOrder(Request $request, Debate $debate): JsonResponse
    {
        $request->validate([
            'judges'                   => ['required', 'array', 'min:1'],
            'judges.*.participant_id'  => ['required', 'integer'],
            'judges.*.judge_order'     => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        // Judge ordering can only change before the debate goes live.
        if (in_array($debate->status, ['live', 'completed', 'cancelled'], true)) {
            return $this->error(
                'لا يمكن تغيير ترتيب القضاة إلا قبل بدء النقاش. | Judge ordering can only be changed before the debate goes live.',
                [], 422
            );
        }

        // Verify all participant_ids are approved judges on this debate.
        $approvedJudgeIds = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        foreach ($request->judges as $entry) {
            if (! in_array((int) $entry['participant_id'], $approvedJudgeIds)) {
                return $this->error(
                    "المشارك {$entry['participant_id']} ليس قاضياً معتمداً في هذا النقاش. | Participant {$entry['participant_id']} is not an approved judge.",
                    [], 422
                );
            }
        }

        DB::transaction(function () use ($request, $debate) {
            // Reset judge_order for all judges first.
            DebateParticipant::where('debate_id', $debate->id)
                ->where('role', 'judge')
                ->update(['judge_order' => null, 'is_chair' => false]);

            foreach ($request->judges as $entry) {
                DebateParticipant::where('id', $entry['participant_id'])
                    ->update(['judge_order' => $entry['judge_order']]);
            }

            // Auto-elect chair: judge with lowest judge_order.
            $chair = DebateParticipant::where('debate_id', $debate->id)
                ->where('role', 'judge')
                ->where('status', 'approved')
                ->whereNotNull('judge_order')
                ->orderBy('judge_order')
                ->first();

            if ($chair) {
                $chair->update(['is_chair' => true]);

                // Notify the main room that the chair changed.
                try {
                    app(\App\Services\LiveKitService::class)->sendDataToRoom(
                        $debate->livekit_room_name,
                        ['event' => 'chair_elected', 'chair_user_id' => (int) $chair->user_id]
                    );
                } catch (\Throwable) {}
            }
        });

        $debate->load('participants.user');

        return $this->success(
            DebateParticipantResource::collection($debate->participants->where('role', 'judge')),
            'تم تعيين ترتيب القضاة. | Judge order set.'
        );
    }

    public function start(Debate $debate): JsonResponse
    {
        if ($debate->status !== 'scheduled') {
            return $this->error(
                'يمكن بدء النقاشات المعلقة فقط. | Only scheduled debates can be started.',
                [],
                422
            );
        }

        $debate->update(['status' => 'live', 'started_at' => now()]);

        return $this->success(new DebateResource($debate), 'تم بدء النقاش. | Debate started.');
    }

    public function destroy(Debate $debate): JsonResponse
    {
        $debate->delete();

        return $this->success(null, 'تم حذف النقاش. | Debate deleted.');
    }
}
