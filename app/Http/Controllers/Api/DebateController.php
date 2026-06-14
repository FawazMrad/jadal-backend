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
use App\Models\TeamMember;
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

    public function register(RegisterDebateRequest $request, Debate $debate): JsonResponse
    {
        $user = $request->user();

        if ($debate->status !== 'scheduled') {
            return $this->error(
                'التسجيل متاح فقط للنقاشات المجدولة. | Registration is only allowed for scheduled debates.',
                [],
                422
            );
        }

        $existing = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($existing) {
            return $this->error('أنت مسجل بالفعل في هذا النقاش. | Already registered.', [], 409);
        }

        $role = $request->role;
        $side = $role === 'judge' ? 'judge' : 'proposition';

        $participant = DebateParticipant::create([
            'debate_id'   => $debate->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'side'        => $side,
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
