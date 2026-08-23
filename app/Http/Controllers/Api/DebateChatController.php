<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateChatMessage;
use App\Models\DebateChatMessageRead;
use App\Models\DebateParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Persistent team chat with read receipts.
 *
 * ADDITIVE to the existing peer `team_chat` data-channel event: clients keep
 * firing that for instant delivery and ALSO call POST here to persist. A
 * rejoining client fills its history via GET. The server never broadcasts
 * chat itself — live delivery stays client→client, untouched.
 *
 * Scope: per (debate, team). The team is always resolved server-side from the
 * caller's own participant row — the client never passes a team_id, so
 * cross-team reads are impossible by construction.
 */
class DebateChatController extends Controller
{
    // ── GET /debates/{debate}/chat ─────────────────────────────────────────────

    public function index(Request $request, Debate $debate): JsonResponse
    {
        $participant = $this->teamParticipant($request, $debate);
        if (! $participant) {
            return $this->noTeamChat();
        }

        $messages = DebateChatMessage::where('debate_id', $debate->id)
            ->where('team_id', $participant->team_id)
            ->with(['sender:id,name', 'reads'])
            ->orderBy('id')
            ->get();

        return $this->success(
            ['messages' => $messages->map(fn (DebateChatMessage $m) => $m->toWire())->values()->all()],
            'تم جلب رسائل الفريق. | Team chat retrieved.'
        );
    }

    // ── POST /debates/{debate}/chat ────────────────────────────────────────────

    public function store(Request $request, Debate $debate): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $participant = $this->teamParticipant($request, $debate);
        if (! $participant) {
            return $this->noTeamChat();
        }

        $message = DebateChatMessage::create([
            'debate_id' => $debate->id,
            'team_id'   => $participant->team_id,
            'sender_id' => $request->user()->id,
            'message'   => $validated['message'],
        ]);

        // The sender has trivially seen their own message.
        DebateChatMessageRead::insertOrIgnore([
            'message_id' => $message->id,
            'user_id'    => $request->user()->id,
            'read_at'    => now(),
        ]);

        $message->load(['sender:id,name', 'reads']);

        return $this->success($message->toWire(), 'تم إرسال الرسالة. | Message sent.', 201);
    }

    // ── POST /debates/{debate}/chat/read ───────────────────────────────────────

    /** Marks every message in the caller's team chat as seen by the caller. */
    public function markRead(Request $request, Debate $debate): JsonResponse
    {
        $participant = $this->teamParticipant($request, $debate);
        if (! $participant) {
            return $this->noTeamChat();
        }

        $userId = (int) $request->user()->id;

        $unseenIds = DebateChatMessage::where('debate_id', $debate->id)
            ->where('team_id', $participant->team_id)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id');

        $now = now();
        DebateChatMessageRead::insertOrIgnore(
            $unseenIds->map(fn ($id) => [
                'message_id' => $id,
                'user_id'    => $userId,
                'read_at'    => $now,
            ])->all()
        );

        return $this->success(
            ['marked_count' => $unseenIds->count()],
            'تم تعليم الرسائل كمقروءة. | Messages marked as read.'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * The caller's team-tagged participant row on this debate (debater or
     * trainer/coach). Judges, viewers, and non-participants have no team chat.
     */
    private function teamParticipant(Request $request, Debate $debate): ?DebateParticipant
    {
        return DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('team_id')
            ->where('status', 'approved')
            ->first();
    }

    private function noTeamChat(): JsonResponse
    {
        return $this->error(
            'لا تملك محادثة فريق في هذا النقاش. | You have no team chat in this debate.',
            [], 403
        );
    }
}
