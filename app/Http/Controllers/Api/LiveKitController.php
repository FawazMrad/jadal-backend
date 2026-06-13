<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveKitController extends Controller
{
    public function __construct(private LiveKitService $liveKit) {}

    /**
     * GET /debates/{debate}/token?room={main|prop|opp|result}
     *
     * Returns a LiveKit JWT for the requested room, enforcing per-room access rules.
     */
    public function getToken(Request $request, int $debateId): JsonResponse
    {
        $user   = $request->user();
        $debate = Debate::findOrFail($debateId);
        $room   = $request->query('room', 'main');

        if (! in_array($room, ['main', 'prop', 'opp', 'result'])) {
            return $this->error('Invalid room. Must be one of: main, prop, opp, result.', [], 422);
        }

        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

        // Validate access and determine permissions.
        [$roomName, $canPublish, $canSubscribe, $canPublishData, $canUpdateOwnMetadata, $roomAdmin, $roleInRoom]
            = match ($room) {
                'main'   => $this->resolveMainRoom($debate, $participant),
                'prop'   => $this->resolvePrepRoom($debate, $participant, 'proposition'),
                'opp'    => $this->resolvePrepRoom($debate, $participant, 'opposition'),
                'result' => $this->resolveResultRoom($debate, $participant),
            };

        if ($roomName === null) {
            return $this->error(
                'غير مصرح لك بالانضمام إلى هذه الغرفة. | You are not authorised to join this room.',
                [], 403
            );
        }

        // Lazily create the room on the LiveKit server on first token issuance.
        try {
            $this->liveKit->createRoomIfMissing($roomName);
        } catch (\Throwable $e) {
            return $this->error('Failed to provision LiveKit room: ' . $e->getMessage(), [], 503);
        }

        $identity = (string) $user->id;
        $token    = $this->liveKit->generateRoomToken(
            $roomName,
            $identity,
            $canPublish,
            $canSubscribe,
            $canPublishData,
            $canUpdateOwnMetadata,
            $roomAdmin
        );

        return $this->success([
            'token'       => $token,
            'url'         => config('services.livekit.url'),
            'room_name'   => $roomName,
            'role_in_room' => $roleInRoom,
        ], 'تم إنشاء رمز الوصول. | Access token generated.');
    }

    // ── Per-room resolution ───────────────────────────────────────────────────

    /**
     * Returns [roomName|null, canPublish, canSubscribe, canPublishData, canUpdateOwn, roomAdmin, role].
     */
    private function resolveMainRoom(Debate $debate, ?DebateParticipant $p): array
    {
        if (! $p) {
            return [null, false, false, false, false, false, null];
        }

        $mainOpen = $debate->status === 'live' && $debate->current_stage > 0;
        if (! $mainOpen) {
            return [null, false, false, false, false, false, null];
        }

        $roomName = $debate->livekit_room_name;

        if ($p->role === 'judge' && $p->is_chair) {
            return [$roomName, true, true, true, true, true, 'judge_chair'];
        }
        if ($p->role === 'judge') {
            return [$roomName, true, true, false, false, false, 'judge_panel'];
        }
        if ($p->role === 'debater') {
            // Determine if this debater is the current speaker.
            $currentPhase = \App\Models\DebatePhase::where('debate_id', $debate->id)
                ->where('order_index', $debate->current_stage)
                ->first();
            $isSpeaker = $currentPhase && (int) $currentPhase->participant_id === (int) $p->id;
            $role = $isSpeaker ? 'debater_speaker' : 'debater_member';
            // Speakers can publish; members can only subscribe.
            return [$roomName, $isSpeaker, true, false, false, false, $role];
        }
        if ($p->role === 'trainer') {
            return [$roomName, false, true, false, false, false, 'trainer'];
        }

        return [$roomName, false, true, false, false, false, 'viewer'];
    }

    private function resolvePrepRoom(Debate $debate, ?DebateParticipant $p, string $side): array
    {
        $roomName = $side === 'proposition' ? $debate->prop_room_name : $debate->opp_room_name;
        if (! $roomName) {
            return [null, false, false, false, false, false, null];
        }

        $prepOpen = in_array($debate->status, ['teams-selected', 'live'])
            && $debate->prep_rooms_opened_at
            && $debate->prep_rooms_opened_at->lte(now());

        if (! $prepOpen) {
            return [null, false, false, false, false, false, null];
        }

        if (! $p || $p->side !== $side || $p->role !== 'debater') {
            return [null, false, false, false, false, false, null];
        }

        return [$roomName, true, true, true, false, false, 'debater_member'];
    }

    private function resolveResultRoom(Debate $debate, ?DebateParticipant $p): array
    {
        $roomName = $debate->result_room_name;
        if (! $roomName) {
            return [null, false, false, false, false, false, null];
        }

        $resultOpen = $debate->status === 'completed' && $debate->result_revealed_at === null;
        if (! $resultOpen) {
            return [null, false, false, false, false, false, null];
        }

        if (! $p || $p->role !== 'judge' || $p->judge_order === null) {
            return [null, false, false, false, false, false, null];
        }

        if ($p->is_chair) {
            return [$roomName, true, true, true, false, false, 'judge_chair'];
        }

        return [$roomName, false, true, false, false, false, 'judge_panel'];
    }
}
