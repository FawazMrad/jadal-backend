<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\Team;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        // Guest mode §4 — this route is optionally authenticated, so a null user
        // is a legitimate tokenless share-link caller. Handled entirely in its
        // own branch; everything below it is the untouched authenticated path.
        if ($user === null) {
            return $this->guestToken($debate, $room);
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

    // ── Guest (tokenless) token issuance ──────────────────────────────────────

    /**
     * Guest mode §4 — a spectator-only token for the MAIN room, and nothing else.
     *
     * Deliberately does NOT reuse resolveMainRoom(): that method grants a
     * publish-capable free-for-all during the lobby (current_stage === 0), which
     * would hand a guest a microphone. Guest grants are hard-coded and never
     * derived from debate state, so no future change to the lobby rules can
     * silently widen them.
     */
    private function guestToken(Debate $debate, string $room): JsonResponse
    {
        // §Q9 — prep and result rooms are never reachable without an account.
        // Checked BEFORE the access window so the reason a guest is refused is
        // the honest one ("this room is not for guests") rather than "expired".
        if ($room !== 'main') {
            return $this->error(
                'هذه الغرفة غير متاحة للضيوف. | This room is not available to guests.',
                [], 403
            );
        }

        // §Q4 — same window the guest live-state endpoint applies.
        if (! $debate->isGuestAccessOpen()) {
            return $this->error(
                'لم يعد هذا النقاش متاحًا للضيوف. | This debate is no longer available to guests.',
                [], 410
            );
        }

        // Room-open check reuses the authenticated path's own rule for the main
        // room, and returns the SAME denial any non-joinable caller gets, per
        // PART C — a guest is not given a bespoke "not open" error.
        if ($debate->status !== 'live' || ! $debate->livekit_room_name) {
            return $this->error(
                'غير مصرح لك بالانضمام إلى هذه الغرفة. | You are not authorised to join this room.',
                [], 403
            );
        }

        $roomName = $debate->livekit_room_name;

        try {
            $this->liveKit->createRoomIfMissing($roomName);
        } catch (\Throwable $e) {
            return $this->error('Failed to provision LiveKit room: ' . $e->getMessage(), [], 503);
        }

        // §Q5 — a FRESH uuid per token request; never reused, never derived from
        // anything about the caller. Non-numeric by construction, so it can
        // never be mistaken for a user id by the webhook's `(int) $identity`.
        $identity = 'guest-' . (string) Str::uuid();

        $token = $this->liveKit->generateRoomToken(
            roomName: $roomName,
            identity: $identity,
            canPublish: false,          // no mic, no camera — ever
            canSubscribe: true,
            canPublishData: false,      // no POI / timer / chat data events
            canUpdateOwnMetadata: false,
            roomAdmin: false,
            displayName: null,          // no label to leak; they are hidden anyway
            hidden: true,               // §Q7 — invisible to other participants
        );

        return $this->success([
            'token'       => $token,
            'url'         => config('services.livekit.url'),
            'room_name'   => $roomName,
            'role_in_room' => 'guest',
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

        // NOTE on the 4th flag (canPublishData): EVERY real participant must be
        // able to publish data-channel messages — that is the transport for the
        // app's realtime signals (POI raise/answer from a speaker, timer ticks
        // and lobby/mute control from the chair, team chat). If it is false the
        // SFU silently drops that participant's data, so the other devices never
        // see the event (e.g. a debater's POI never reaches the chair, and a
        // panel judge promoted to chair can't broadcast until they rejoin). Only
        // pure spectators (viewers) stay data-off.

        // Chair gets full control in both lobby and debate mode.
        if ($isChair) {
            return [$roomName, true, true, true, true, true, 'judge_chair'];
        }

        // Lobby (stage 0): a free-for-all room — everyone may publish & talk.
        if ($isLobby) {
            return [$roomName, true, true, true, false, false, $this->mainRole($debate, $p)];
        }

        // Debate mode (stage > 0): permissions by role.
        if ($p && $p->role === 'judge') {
            return [$roomName, true, true, true, false, false, 'judge_panel'];
        }
        if ($p && $p->role === 'debater') {
            return [$roomName, true, true, true, false, false, 'debater'];
        }
        if ($this->isTrainerOfDebateTeam($debate, $p)) {
            // No audio/video, but may still publish data (e.g. team chat).
            return [$roomName, false, true, true, false, false, 'trainer'];
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
     * Result room. Open during the result phase — speeches are done but the
     * debate is still `live` (not yet closed). JUDGES ONLY: any approved judge
     * with an assigned order may publish; everyone else is rejected (403).
     */
    private function resolveResultRoom(Debate $debate, ?DebateParticipant $p): array
    {
        $roomName = $debate->result_room_name;
        if (! $roomName) {
            return [null, false, false, false, false, false, null];
        }

        if (! $debate->isInResultPhase()) {
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
