<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\ListDebatesRequest;
use App\Http\Requests\Debate\RegisterDebateRequest;
use App\Http\Requests\Debate\SubmitResultRequest;
use App\Http\Resources\DebateDetailResource;
use App\Http\Resources\DebateParticipantResource;
use App\Http\Resources\DebateResource;
use App\Http\Resources\DebateResultResource;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateResult;
use App\Models\Feedbacks;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function show(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        $isParticipant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant && $user->role !== 'admin') {
            return $this->error('غير مصرح بالوصول لهذا النقاش. | Access denied.', [], 403);
        }

        $debate->load(['format', 'motion', 'createdBy', 'participants.user', 'phases', 'result.judge']);

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

    public function submitResult(SubmitResultRequest $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        if ($debate->status !== 'live') {
            return $this->error(
                'يمكن تقديم النتائج فقط للنقاشات الجارية. | Results can only be submitted for live debates.',
                [],
                422
            );
        }

        $isChairJudge = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->where('status', 'approved')
            ->exists();

        if (! $isChairJudge) {
            return $this->error(
                'فقط قاضي الرئاسة يمكنه تقديم النتائج. | Only the chair judge can submit results.',
                [],
                403
            );
        }

        if ($debate->result()->exists()) {
            return $this->error('تم تقديم النتائج بالفعل لهذا النقاش. | Results already submitted.', [], 409);
        }

        $result = DB::transaction(function () use ($request, $debate, $user) {
            $result = DebateResult::create([
                'debate_id'     => $debate->id,
                'judge_id'      => $user->id,
                'winning_side'  => $request->winning_side,
                'scores'        => $request->scores,
                'summary_notes' => $request->summary_notes,
                'submitted_at'  => now(),
            ]);

            $debate->update(['status' => 'completed', 'ended_at' => now()]);

            return $result;
        });

        return $this->success(
            new DebateResultResource($result->load('judge')),
            'تم تقديم النتائج. | Results submitted.',
            201
        );
    }
}
