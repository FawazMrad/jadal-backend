<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\ListDebatesRequest;
use App\Http\Requests\Debate\RegisterDebateRequest;
use App\Http\Requests\Debate\TeamRosterRequest;
use App\Http\Resources\DebateDetailResource;
use App\Http\Resources\DebateParticipantResource;
use App\Http\Resources\DebateResource;
use App\Http\Resources\PublicUserResource;
use App\Models\Debate;
use App\Models\DebateFormat;
use App\Models\DebateParticipant;
use App\Models\Feedbacks;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Push\DebateNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DebateController extends Controller
{
    /**
     * Public browse endpoint: any authenticated user can list debates across all
     * statuses. Defaults to upcoming/live when no status filter is supplied.
     */
    public function index(ListDebatesRequest $request): JsonResponse
    {
        $statuses = $request->input('statuses') ?? [
            'scheduled', 'announced', 'teams-selected', 'live',
        ];

        $userId = $request->user()->id;

        $query = Debate::query()
            ->whereIn('status', $statuses)
            ->with(['format', 'motion.frameworks'])
            // Constrained eager-load so DebateResource can resolve
            // my_participation_status without an N+1 per row.
            ->with(['participants' => fn ($q) => $q->where('user_id', $userId)]);

        if ($request->filled('format_id')) {
            $query->where('format_id', $request->integer('format_id'));
        }
        if ($request->filled('motion_id')) {
            $query->where('motion_id', $request->integer('motion_id'));
        }
        if ($request->filled('from_date')) {
            $query->where('scheduled_at', '>=', $request->date('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('scheduled_at', '<=', $request->date('to_date'));
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhere('tag', 'LIKE', "%{$term}%")
                  ->orWhereHas('motion', fn ($m) => $m->where('text', 'LIKE', "%{$term}%"));
            });
        }

        $sort = $request->input('sort', 'scheduled_asc');
        match ($sort) {
            'scheduled_desc' => $query->orderBy('scheduled_at', 'desc'),
            'created_desc'   => $query->orderBy('created_at', 'desc'),
            default          => $query->orderBy('scheduled_at', 'asc'),
        };

        $perPage = (int) $request->input('per_page', 15);
        $debates = $query->paginate($perPage);

        return $this->paginated(
            DebateResource::collection($debates),
            $debates,
            'Debates retrieved successfully.'
        );
    }

    /**
     * Sprinkles §8: GET /debates/search
     *
     * q matches debate title OR motion text. Filters combine with AND across
     * dimensions and OR within one multi-select dimension. No status
     * restriction — the profile screens search past (completed/cancelled)
     * debates too. Response: same paginated shape as GET /debates.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'              => ['sometimes', 'nullable', 'string', 'max:200'],
            'status'         => ['sometimes', 'array'],
            'status.*'       => ['string', Rule::in(['scheduled', 'announced', 'teams-selected', 'live', 'completed', 'cancelled'])],
            'format_id'      => ['sometimes', 'array'],
            'format_id.*'    => ['integer'],
            'debate_tag'     => ['sometimes', 'array'],
            'debate_tag.*'   => ['string', 'max:100'],
            'framework_id'   => ['sometimes', 'array'],
            'framework_id.*' => ['integer'],
            'judge_id'       => ['sometimes', 'array'],
            'judge_id.*'     => ['integer'],
            'team_id'        => ['sometimes', 'array'],
            'team_id.*'      => ['integer'],
            'user_id'        => ['sometimes', 'array'],
            'user_id.*'      => ['integer'],
            'date_from'      => ['sometimes', 'nullable', 'date'],
            'date_to'        => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;

        $query = Debate::query()
            ->with(['format', 'motion.frameworks'])
            // Same constrained eager-load as index() so DebateResource can
            // resolve my_participation_status without an N+1.
            ->with(['participants' => fn ($q) => $q->where('user_id', $userId)]);

        if (! empty($validated['q'])) {
            $term = $validated['q'];
            $query->where(function ($q) use ($term) {
                $q->where('title', 'LIKE', "%{$term}%")
                  ->orWhereHas('motion', fn ($m) => $m->where('text', 'LIKE', "%{$term}%"));
            });
        }

        if (! empty($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }
        if (! empty($validated['format_id'])) {
            $query->whereIn('format_id', $validated['format_id']);
        }
        if (! empty($validated['debate_tag'])) {
            $query->whereIn('tag', $validated['debate_tag']);
        }
        if (! empty($validated['framework_id'])) {
            $ids = $validated['framework_id'];
            $query->whereHas('motion.frameworks', fn ($q) => $q->whereIn('motion_frameworks.id', $ids));
        }
        if (! empty($validated['judge_id'])) {
            $ids = $validated['judge_id'];
            $query->whereHas('participants', fn ($q) => $q->where('role', 'judge')->whereIn('user_id', $ids));
        }
        if (! empty($validated['team_id'])) {
            $ids = $validated['team_id'];
            $query->where(function ($q) use ($ids) {
                $q->whereIn('proposition_team_id', $ids)
                  ->orWhereIn('opposition_team_id', $ids)
                  ->orWhereHas('participants', fn ($p) => $p->whereIn('team_id', $ids));
            });
        }
        if (! empty($validated['user_id'])) {
            $ids = $validated['user_id'];
            $query->whereHas('participants', fn ($q) => $q->whereIn('user_id', $ids));
        }
        if (! empty($validated['date_from'])) {
            $query->where('scheduled_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->where('scheduled_at', '<=', \Carbon\Carbon::parse($validated['date_to'])->endOfDay());
        }

        $debates = $query->orderBy('scheduled_at', 'desc')
            ->paginate((int) ($validated['per_page'] ?? 15));

        return $this->paginated(
            DebateResource::collection($debates),
            $debates,
            'تم البحث في النقاشات. | Debates searched.'
        );
    }

    /**
     * Sprinkles §8: GET /debates/tags/distinct — every debate `tag` value
     * currently in use (debate tags are free text, not managed entities).
     */
    public function distinctTags(): JsonResponse
    {
        $tags = Debate::whereNotNull('tag')
            ->where('tag', '!=', '')
            ->distinct()
            ->orderBy('tag')
            ->pluck('tag')
            ->values()
            ->all();

        return $this->success(['tags' => $tags], 'تم جلب وسوم النقاشات. | Debate tags retrieved.');
    }

    public function show(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $isParticipant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant && $user->role !== 'admin') {
            return $this->error('غير مصرح بالوصول لهذا النقاش. | Access denied.', [], 403);
        }

        $debate->load(['format', 'motion', 'createdBy', 'participants.user', 'participants.team', 'phases', 'result.judge']);

        $feedbackQuery = Feedbacks::where('debate_id', $debate->id)->with(['fromUser', 'toUser']);

        $feedbacks = match ($user->role) {
            'debater' => $feedbackQuery->where('to_user_id', $user->id)->get(),
            'trainer' => $feedbackQuery->whereIn(
                'to_user_id',
                TeamMember::where('status', 'active')
                    ->whereHas('team', fn ($q) => $q->where('trainer_id', $user->id))
                    ->pluck('user_id')
            )->get(),
            'judge'  => $feedbackQuery->where('from_user_id', $user->id)->get(),
            default  => $feedbackQuery->get(),
        };

        $debate->setRelation('feedbacks', $feedbacks);

        return $this->success(new DebateDetailResource($debate), 'تم جلب النقاش. | Debate retrieved.');
    }

    /**
     * Self-registration for a debate. Three variants via the `as` field:
     *  - debater : one pending row, side left null until an admin assigns it
     *  - judge   : one pending row, side = judge
     *  - team    : one pending row per active (status=current) team member +
     *              one for the team's coach (teams.created_by), all tagged team_id
     *
     * All rows are keyed on (debate_id, user_id) so the admin assignment upsert
     * can still cleanly match and upgrade them.
     */
    public function register(RegisterDebateRequest $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        // Registration is only open while the debate is still 'scheduled'
        // (preserves the pre-existing gate).
        if ($debate->status !== 'scheduled') {
            return $this->error(
                'التسجيل متاح فقط للنقاشات المجدولة. | Registration is only allowed for scheduled debates.',
                [],
                422
            );
        }

        return $request->input('as') === 'team'
            ? $this->registerTeam($debate, $user, (int) $request->input('team_id'))
            : $this->registerSolo($debate, $user, $request->input('as'));
    }

    private function registerSolo(Debate $debate, User $user, string $as): JsonResponse
    {
        // The account role must match the requested variant.
        if ($user->role !== $as) {
            return $this->error(
                "لا يمكنك التسجيل بهذه الصفة. | Your account role does not allow registering as {$as}.",
                [],
                403
            );
        }

        if ($this->alreadyRegistered($debate, (int) $user->id)) {
            return $this->error('أنت مسجل بالفعل في هذا النقاش. | Already registered.', [], 409);
        }

        $participant = DebateParticipant::create([
            'debate_id'   => $debate->id,
            'user_id'     => $user->id,
            'team_id'     => null,
            'role'        => $as,
            // Debaters: side is assigned later by the admin. Judges: always 'judge'.
            'side'        => $as === 'judge' ? 'judge' : null,
            'status'      => 'pending',
            'is_chair'    => false,
            'is_attended' => false,
        ]);

        return $this->success(
            new DebateParticipantResource($participant->load('user')),
            'تم التسجيل. | Registered successfully.',
            201
        );
    }

    private function registerTeam(Debate $debate, User $user, int $teamId): JsonResponse
    {
        $team = Team::find($teamId); // existence already validated by the FormRequest

        if ($team->is_random) {
            return $this->error(
                'لا يمكن تسجيل فريق عشوائي. | Random/ad-hoc teams cannot register.',
                [],
                422
            );
        }

        // Only the team leader or its coach (created_by) may register the team.
        $isLeader = (int) $team->leader_id === (int) $user->id;
        $isCoach  = (int) $team->created_by === (int) $user->id;
        if (! $isLeader && ! $isCoach) {
            return $this->error(
                'فقط قائد الفريق أو مدربه يمكنه تسجيل الفريق. | Only the team leader or coach can register the team.',
                [],
                403
            );
        }

        if ($this->alreadyRegistered($debate, (int) $user->id)) {
            return $this->error('أنت مسجل بالفعل في هذا النقاش. | Already registered.', [], 409);
        }

        // Active members = team_members with status 'current' (the real enum value).
        $memberIds = TeamMember::where('team_id', $team->id)
            ->where('status', 'current')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $trainerId = (int) $team->created_by;

        $affectedUserIds = DB::transaction(function () use ($debate, $team, $memberIds, $trainerId) {
            $affected = [];

            // One pending debater row per active member.
            foreach ($memberIds as $memberId) {
                $affected[] = $memberId;
                DebateParticipant::firstOrCreate(
                    ['debate_id' => $debate->id, 'user_id' => $memberId],
                    [
                        'team_id'     => $team->id,
                        'role'        => 'debater',
                        'side'        => null,
                        'status'      => 'pending',
                        'is_chair'    => false,
                        'is_attended' => false,
                    ]
                );
            }

            // One pending row for the team's coach/trainer.
            $affected[] = $trainerId;
            DebateParticipant::firstOrCreate(
                ['debate_id' => $debate->id, 'user_id' => $trainerId],
                [
                    'team_id'     => $team->id,
                    'role'        => 'trainer',
                    'side'        => 'trainer',
                    'status'      => 'pending',
                    'is_chair'    => false,
                    'is_attended' => false,
                ]
            );

            return $affected;
        });

        $rows = DebateParticipant::where('debate_id', $debate->id)
            ->whereIn('user_id', array_values(array_unique($affectedUserIds)))
            ->with('user')
            ->get();

        return $this->success(
            DebateParticipantResource::collection($rows),
            'تم تسجيل الفريق. | Team registered.',
            201
        );
    }

    private function alreadyRegistered(Debate $debate, int $userId): bool
    {
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * V12 §1: GET /debates/{debate}/registerable-teams
     *
     * The teams the caller may register for THIS debate — the teams they lead or
     * coach (created_by). A leader usually owns 1; a trainer may own several. Each
     * row carries an eligibility flag + reason so the FE can disable bad picks.
     */
    public function registerableTeams(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        // Teams the caller leads or coaches that can actually play (active, not random).
        $teams = Team::where('status', 'active')
            ->where('is_random', false)
            ->where(fn ($q) => $q->where('leader_id', $user->id)->orWhere('created_by', $user->id))
            ->get();

        // Team ids that already have any registration row on this debate.
        $registeredTeamIds = DebateParticipant::where('debate_id', $debate->id)
            ->whereNotNull('team_id')
            ->pluck('team_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        // The team the caller themselves already registered for this debate (if any).
        $alreadyRegisteredTeamId = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->whereNotNull('team_id')
            ->value('team_id');

        $registrationOpen = $debate->status === 'scheduled';

        $rows = $teams->map(function (Team $team) use ($registeredTeamIds, $registrationOpen) {
            $membersCount = TeamMember::where('team_id', $team->id)
                ->where('status', 'current')
                ->count();

            $reason = match (true) {
                ! $registrationOpen                              => 'registration_closed',
                in_array((int) $team->id, $registeredTeamIds, true) => 'already_registered',
                $membersCount < DebateFormat::SPEAKERS_PER_SIDE  => 'too_few_members',
                default                                          => null,
            };

            return [
                'id'                => (int) $team->id,
                'name'              => $team->name,
                'members_count'     => $membersCount,
                'eligible'          => $reason === null,
                'ineligible_reason' => $reason,
            ];
        })->values()->all();

        return $this->success([
            'debate_id'                  => (int) $debate->id,
            'already_registered_team_id' => $alreadyRegisteredTeamId !== null ? (int) $alreadyRegisteredTeamId : null,
            'teams'                      => $rows,
        ], 'تم جلب الفرق القابلة للتسجيل. | Registerable teams retrieved.');
    }

    /**
     * V12 §3: GET /debates/{debate}/registrations
     *
     * Who has registered for this debate, split into teams / judges / solo
     * applicants — drives the three "registered" dialogs on the registration
     * screen. Derived from the debate_participants rows registration creates.
     */
    public function registrations(Debate $debate): JsonResponse
    {
        $rows = DebateParticipant::where('debate_id', $debate->id)->with('user')->get();

        // Teams = rows that carry a team_id, grouped. members_count counts debaters.
        $teams = $rows->filter(fn ($r) => $r->team_id !== null)
            ->groupBy('team_id')
            ->map(function ($group, $teamId) {
                $team = Team::find($teamId);

                return [
                    'team' => [
                        'id'   => (int) $teamId,
                        'name' => $team?->name,
                    ],
                    'members_count' => $group->where('role', 'debater')->count(),
                    'registered_at' => $group->min('created_at')?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $judges = $rows->where('role', 'judge')->values()
            ->map(fn ($r) => $this->registrantRow($r))
            ->all();

        // Solo = individual debater applicants (no team).
        $solo = $rows->where('role', 'debater')->whereNull('team_id')->values()
            ->map(fn ($r) => $this->registrantRow($r))
            ->all();

        return $this->success([
            'teams'  => $teams,
            'judges' => $judges,
            'solo'   => $solo,
        ], 'تم جلب المسجّلين. | Registrants retrieved.');
    }

    /** @return array{user: PublicUserResource|null, registered_at: string|null} */
    private function registrantRow(DebateParticipant $r): array
    {
        return [
            'user'          => $r->relationLoaded('user') && $r->user ? new PublicUserResource($r->user) : null,
            'registered_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /**
     * POST /debates/{debate}/team-roster
     *
     * The team itself (leader or coach) finalizes who plays — this AUTO-APPROVES
     * the chosen 3 and auto-rejects the rest of the team's pending debaters. No
     * admin review step. Side is taken from the admin's prior side declaration
     * (proposition_team_id / opposition_team_id), never chosen here.
     *
     * Replay (allowed while status = scheduled): the call is idempotent — the 3
     * chosen become approved on the team's side, every OTHER debater of the team
     * becomes rejected, and the trainer is approved. Re-running with a different
     * trio cleanly replaces the previous selection (a previously-rejected debater
     * picked in the new trio is re-approved).
     */
    public function teamRoster(TeamRosterRequest $request, Debate $debate): JsonResponse
    {
        $user       = $request->user();
        $teamId     = (int) $request->team_id;
        $speakerIds = array_map('intval', $request->speaker_user_ids);

        if ($debate->status !== 'scheduled') {
            return $this->error(
                'يمكن تحديد القائمة فقط للنقاشات المجدولة. | Roster can only be set while the debate is scheduled.',
                [], 422
            );
        }

        // The team must be pre-declared on one of this debate's sides.
        $side = match ($teamId) {
            (int) $debate->proposition_team_id => 'proposition',
            (int) $debate->opposition_team_id  => 'opposition',
            default                            => null,
        };
        if ($side === null) {
            return $this->error(
                'الفريق غير مرتبط بأي جانب في هذا النقاش. | This team is not linked to a side of this debate yet.',
                [], 422
            );
        }

        // Caller must be the team's leader or coach (same check as team registration).
        $team = Team::find($teamId);
        if ((int) $team->leader_id !== (int) $user->id && (int) $team->created_by !== (int) $user->id) {
            return $this->error(
                'فقط قائد الفريق أو مدربه يمكنه تحديد القائمة. | Only the team leader or coach can set the roster.',
                [], 403
            );
        }

        // All 3 chosen must be debater participants of this team on this debate.
        $teamDebaterIds = DebateParticipant::where('debate_id', $debate->id)
            ->where('team_id', $teamId)
            ->where('role', 'debater')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($speakerIds as $sid) {
            if (! in_array($sid, $teamDebaterIds, true)) {
                return $this->error(
                    "المستخدم {$sid} ليس ضمن قائمة المتحدثين المعلقة لهذا الفريق. | User {$sid} is not in this team's debater pool.",
                    [], 422
                );
            }
        }

        DB::transaction(function () use ($debate, $teamId, $speakerIds, $side) {
            // Chosen debaters → approved on the team's declared side.
            DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $teamId)
                ->where('role', 'debater')
                ->whereIn('user_id', $speakerIds)
                ->update(['status' => 'approved', 'side' => $side]);

            // Every other debater of the team → rejected (also handles replay).
            DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $teamId)
                ->where('role', 'debater')
                ->whereNotIn('user_id', $speakerIds)
                ->update(['status' => 'rejected']);

            // The team's trainer/coach → approved, side stays 'trainer'.
            DebateParticipant::where('debate_id', $debate->id)
                ->where('team_id', $teamId)
                ->where('role', 'trainer')
                ->update(['status' => 'approved', 'side' => 'trainer']);
        });

        $this->bumpToAnnouncedIfReady($debate->fresh());

        $rows = DebateParticipant::where('debate_id', $debate->id)
            ->where('team_id', $teamId)
            ->with('user')
            ->get();

        return $this->success(
            DebateParticipantResource::collection($rows),
            'تم تحديد قائمة الفريق. | Team roster finalized.'
        );
    }

    /**
     * Bump a scheduled debate to 'announced' once both sides have an approved
     * debater and there is at least one approved judge. Mirrors the trigger in
     * AdminDebateController::assignParticipants so team self-selection can also
     * complete the lineup.
     */
    private function bumpToAnnouncedIfReady(Debate $debate): void
    {
        if ($debate->status !== 'scheduled') {
            return;
        }

        $hasProp  = $debate->participants()->where('side', 'proposition')->where('role', 'debater')->where('status', 'approved')->exists();
        $hasOpp   = $debate->participants()->where('side', 'opposition')->where('role', 'debater')->where('status', 'approved')->exists();
        $hasJudge = $debate->participants()->where('role', 'judge')->where('status', 'approved')->exists();

        if ($hasProp && $hasOpp && $hasJudge) {
            $debate->update(['status' => 'announced']);

            // #1 — the SAME user-visible transition as the admin-announce path,
            // which already notifies. Previously this route (a team completing
            // its own roster) reached `announced` silently, so whether
            // participants heard about it depended on how the lineup happened
            // to be filled.
            app(DebateNotifier::class)->debateStateChangedFrom($debate, 'scheduled');
        }
    }

}
