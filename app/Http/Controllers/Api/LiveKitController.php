<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveKitController extends Controller
{
    public function __construct(private LiveKitService $liveKit) {}

    /**
     * GET /debates/{debate}/token?room={main|prop|opp|result}
     *
     * Returns a LiveKit JWT for the requested room. Permissions are based on
     * role + room only (no per-stage gating); the frontend gates UI controls.
     */
    public function getToken(Request $request, Debate $debate): JsonResponse
    {
        $user = $request->user();
        $room = $request->query('room', 'main');

        if (! in_array($room, ['main', 'prop', 'opp', 'result'], true)) {
            return $this->error('Invalid room. Must be one of: main, prop, opp, result.', [], 422);
        }

        // The user's participant row (if any). Viewers/non-participants have none.
        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->first();

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

        try {
            $this->liveKit->createRoomIfMissing($roomName);
        } catch (\Throwable $e) {
            return $this->error('Failed to provision LiveKit room: ' . $e->getMessage(), [], 503);
        }

        $token = $this->liveKit->generateRoomToken(
            $roomName,
            (string) $user->id,
            $canPublish,
            $canSubscribe,
            $canPublishData,
            $canUpdateOwnMetadata,
            $roomAdmin,
            $user->name // display name → Participant.name on clients
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
     * Main room. Open for the whole `live` status. Returns:
     * [roomName|null, canPublish, canSubscribe, canPublishData, canUpdateOwn, roomAdmin, role].
     */
    private function resolveMainRoom(Debate $debate, ?DebateParticipant $p): array
    {
        // The main room only exists while the debate is live.
        if ($debate->status !== 'live') {
            return [null, false, false, false, false, false, null];
        }

        $roomName = $debate->livekit_room_name;
        $isLobby  = $debate->current_stage === 0;
        $isChair  = $p && $p->role === 'judge' && $p->is_chair;

        // Chair gets full control in both lobby and debate mode.
        if ($isChair) {
            return [$roomName, true, true, true, true, true, 'judge_chair'];
        }

        // Lobby (stage 0): a free-for-all room — everyone may publish & talk.
        if ($isLobby) {
            return [$roomName, true, true, false, false, false, $this->mainRole($debate, $p)];
        }

        // Debate mode (stage > 0): permissions by role.
        if ($p && $p->role === 'judge') {
            return [$roomName, true, true, false, false, false, 'judge_panel'];
        }
        if ($p && $p->role === 'debater') {
            return [$roomName, true, true, false, false, false, 'debater'];
        }
        if ($this->isTrainerOfDebateTeam($debate, $p)) {
            return [$roomName, false, true, false, false, false, 'trainer'];
        }

        // Everyone else (non-participants, viewers): subscribe only.
        return [$roomName, false, true, false, false, false, 'viewer'];
    }

    /**
     * Prep room. Open ONLY during the lobby (current_stage === 0). Only debaters
     * on that side may join; the team trainer is explicitly excluded.
     */
    private function resolvePrepRoom(Debate $debate, ?DebateParticipant $p, string $side): array
    {
        $roomName = $side === 'proposition' ? $debate->prop_room_name : $debate->opp_room_name;
        if (! $roomName) {
            return [null, false, false, false, false, false, null];
        }

        $prepOpen = $debate->prep_rooms_opened_at
            && $debate->prep_rooms_opened_at->lte(now())
            && in_array($debate->status, ['teams-selected', 'live'], true)
            && $debate->current_stage === 0;

        if (! $prepOpen) {
            return [null, false, false, false, false, false, null];
        }

        // Only approved debaters on this side. Trainers (and anyone else) → 403.
        if (! $p || $p->side !== $side || $p->role !== 'debater') {
            return [null, false, false, false, false, false, null];
        }

        return [$roomName, true, true, true, false, false, 'debater'];
    }

    /**
     * Result room. Open after the debate completes and before the reveal. Any
     * approved judge with an assigned order may publish.
     */
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

        $role = $p->is_chair ? 'judge_chair' : 'judge_panel';

        // All judges (chair and panel) may publish in the result room.
        return [$roomName, true, true, true, false, false, $role];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Structural role label for the main room (non-chair).
     */
    private function mainRole(Debate $debate, ?DebateParticipant $p): string
    {
        if ($p && $p->role === 'judge') {
            return 'judge_panel';
        }
        if ($p && $p->role === 'debater') {
            return 'debater';
        }
        if ($this->isTrainerOfDebateTeam($debate, $p)) {
            return 'trainer';
        }

        return 'viewer';
    }

    /**
     * A user is a "trainer of a debate team" if they are a trainer participant
     * whose team_id matches one of the two (non-random) teams fielding debaters.
     */
    private function isTrainerOfDebateTeam(Debate $debate, ?DebateParticipant $p): bool
    {
        if (! $p || $p->role !== 'trainer' || ! $p->team_id) {
            return false;
        }

        $teamIds = DebateParticipant::where('debate_id', $debate->id)
            ->whereIn('side', ['proposition', 'opposition'])
            ->where('role', 'debater')
            ->whereNotNull('team_id')
            ->pluck('team_id')
            ->unique()
            ->all();

        if (empty($teamIds)) {
            return false;
        }

        // Random teams have no trainer.
        $nonRandomTeamIds = Team::whereIn('id', $teamIds)
            ->where('is_random', false)
            ->pluck('id')
            ->all();

        return in_array((int) $p->team_id, array_map('intval', $nonRandomTeamIds), true);
    }
}
