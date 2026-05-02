<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Debate\AssignParticipantsRequest;
use App\Http\Requests\Debate\StoreDebateRequest;
use App\Http\Requests\Debate\UpdateDebateRequest;
use App\Http\Requests\Debate\UpdateParticipantStatusRequest;
use App\Http\Resources\DebateDetailResource;
use App\Http\Resources\DebateParticipantResource;
use App\Http\Resources\DebateResource;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Feedbacks;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminDebateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $debates = Debate::with(['format', 'motion'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->tag, fn ($q) => $q->where('tag', $request->tag))
            ->latest()
            ->paginate(20);

        return $this->paginated($debates, DebateResource::class, 'تم جلب النقاشات. | Debates retrieved.');
    }

    public function store(StoreDebateRequest $request): JsonResponse
    {
        $debate = Debate::create(array_merge($request->validated(), [
            'created_by'        => $request->user()->id,
            'status'            => 'pending',
            'livekit_room_name' => 'debate-' . Str::uuid(),
        ]));

        $debate->load(['format', 'motion', 'createdBy']);

        return $this->success(new DebateDetailResource($debate), 'تم إنشاء النقاش. | Debate created.', 201);
    }

    public function show(Debate $debate): JsonResponse
    {
        $debate->load(['format', 'motion', 'createdBy', 'participants.user', 'phases', 'result.judge']);

        $feedbacks = Feedbacks::where('debate_id', $debate->id)
            ->with(['fromUser', 'toUser'])
            ->get();
        $debate->setRelation('feedbacks', $feedbacks);

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
        foreach ($request->participants as $p) {
            $attrs = [
                'team_id'              => $p['team_id'] ?? null,
                'role'                 => $p['role'],
                'side'                 => $p['side'],
                'status'               => 'approved',
                'is_chair'             => $p['is_chair'] ?? false,
                'is_attended'          => false,
                'speaking_phase_order' => null,
            ];

            DebateParticipant::updateOrCreate(
                ['debate_id' => $debate->id, 'user_id' => $p['user_id']],
                $attrs
            );

            if (! empty($p['team_id'])) {
                $memberIds = TeamMember::where('team_id', $p['team_id'])
                    ->where('status', 'active')
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

        $debate->load('participants.user');

        return $this->success(
            DebateParticipantResource::collection($debate->participants),
            'تم تعيين المشاركين. | Participants assigned.'
        );
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

    public function start(Debate $debate): JsonResponse
    {
        if ($debate->status !== 'pending') {
            return $this->error(
                'يمكن بدء النقاشات المعلقة فقط. | Only pending debates can be started.',
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
