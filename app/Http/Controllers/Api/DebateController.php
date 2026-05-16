<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $debates = Debate::with(['format', 'motion'])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return $this->paginated(DebateResource::collection($debates), $debates, 'تم جلب نقاشاتك. | Your debates retrieved.');
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
