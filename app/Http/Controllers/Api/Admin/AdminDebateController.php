<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\AssignParticipantsRequest;
use App\Http\Requests\SearchListRequest;
use App\Http\Requests\Debate\StoreDebateRequest;
use App\Http\Requests\Debate\UpdateDebateRequest;
use App\Http\Requests\Debate\UpdateParticipantStatusRequest;
use App\Http\Resources\DebateDetailResource;
use App\Http\Resources\DebateParticipantResource;
use App\Http\Resources\DebateResource;
use App\Models\Debate;
use App\Models\DebateParticipant;
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
        $debate->load(['format', 'motion', 'createdBy', 'participants.user', 'phases', 'result.judge', 'feedbacks.fromUser', 'feedbacks.toUser']);

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

                if (! empty($p['team_id'])) {
                    $memberIds = TeamMember::where('team_id', $p['team_id'])
                        ->where('status', 'current')
                        ->where('user_id', '!=', $p['user_id'])
                        ->pluck('user_id');

                    foreach ($memberIds as $memberId) {
                        DebateParticipant::updateOrCreate(
                            ['debate_id' => $debate->id, 'user_id' => $memberId],
                            [
                                'team_id'              => $p['team_id'],
                                'role'                 => $p['role'],
                                'side'                 => $p['side'],
                                'status'               => 'approved',
                                'is_chair'             => false,
                                'is_attended'          => false,
                                'speaking_phase_order' => null,
                            ]
                        );
                    }
                }
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
