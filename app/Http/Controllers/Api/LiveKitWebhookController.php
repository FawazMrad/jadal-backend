<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Services\LiveKitService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class LiveKitWebhookController extends Controller
{
    public function __construct(private LiveKitService $liveKit) {}

    public function handle(Request $request): Response
    {
        // Verify the webhook signature using the LiveKit secret.
        $authHeader = $request->header('Authorization', '');
        if (! $this->verifySignature($request->getContent(), $authHeader)) {
            return response('Unauthorized', 401);
        }

        $payload = $request->json()->all();
        $event   = $payload['event'] ?? null;

        match ($event) {
            'participant_joined' => $this->onParticipantJoined($payload),
            'participant_left'   => $this->onParticipantLeft($payload),
            'egress_ended'       => $this->onEgressEnded($payload),
            'room_finished'      => $this->onRoomFinished($payload),
            default              => Log::debug('LiveKit webhook: unhandled event', ['event' => $event]),
        };

        return response('OK', 200);
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    private function onParticipantJoined(array $payload): void
    {
        $roomName = $payload['room']['name'] ?? null;
        $identity = $payload['participant']['identity'] ?? null;

        if (! $roomName || ! $identity) {
            return;
        }

        $debate      = $this->findDebateByRoom($roomName);
        $userId      = (int) $identity;
        $participant = $debate
            ? DebateParticipant::where('debate_id', $debate->id)
                ->where('user_id', $userId)
                ->first()
            : null;

        if (! $participant) {
            return;
        }

        $participant->update(['is_attended' => true]);

        // Chair election — only relevant in the main room.
        if ($debate && $roomName === $debate->livekit_room_name && $participant->role === 'judge') {
            $this->electChair($debate);
        }

        // Broadcast attendance to main room.
        if ($debate && $roomName === $debate->livekit_room_name) {
            try {
                $this->liveKit->sendDataToRoom(
                    $debate->livekit_room_name,
                    ['event' => 'participant_attended', 'user_id' => $userId]
                );
            } catch (\Throwable) {}
        }
    }

    private function onParticipantLeft(array $payload): void
    {
        $roomName = $payload['room']['name'] ?? null;
        $identity = $payload['participant']['identity'] ?? null;

        if (! $roomName || ! $identity) {
            return;
        }

        $debate = $this->findDebateByRoom($roomName);
        if (! $debate || $roomName !== $debate->livekit_room_name) {
            return;
        }

        $userId      = (int) $identity;
        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $userId)
            ->first();

        // If the leaver was the chair and the debate is live, re-elect.
        if ($participant && $participant->is_chair && $debate->status === 'live') {
            $this->electChair($debate, excludeUserId: $userId);
        }
    }

    private function onEgressEnded(array $payload): void
    {
        $egressId = $payload['egress_id'] ?? null;
        if (! $egressId) {
            return;
        }

        $phase = DebatePhase::where('egress_id', $egressId)->first();
        if (! $phase) {
            return;
        }

        // Extract recording file path/URL from the payload.
        $fileResults = $payload['file_results'] ?? $payload['outputs'] ?? [];
        $audioUrl    = null;

        foreach ($fileResults as $result) {
            if (! empty($result['filename'])) {
                $audioUrl = $result['filename'];
                break;
            }
            if (! empty($result['download_url'])) {
                $audioUrl = $result['download_url'];
                break;
            }
        }

        if ($audioUrl) {
            $phase->update(['audio_url' => $audioUrl]);
        }
    }

    private function onRoomFinished(array $payload): void
    {
        $roomName = $payload['room']['name'] ?? null;
        if (! $roomName) {
            return;
        }

        $debate = $this->findDebateByRoom($roomName);
        if (! $debate) {
            Log::info("LiveKit room_finished: no debate found for room {$roomName}");
            return;
        }

        // Main room finishing while debate is live: do NOT auto-complete.
        // Chair must call /next-stage past the last stage explicitly.
        Log::info("LiveKit room_finished: room {$roomName} for debate {$debate->id} — no action taken.");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Elect the chair judge among attended judges with lowest judge_order.
     * If excludeUserId is provided, that judge is skipped (they just left).
     */
    private function electChair(Debate $debate, ?int $excludeUserId = null): void
    {
        // Reset all chair flags.
        DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->update(['is_chair' => false]);

        $query = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->where('is_attended', true)
            ->whereNotNull('judge_order')
            ->orderBy('judge_order');

        if ($excludeUserId) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        $newChair = $query->first();

        if ($newChair) {
            $newChair->update(['is_chair' => true]);
        }
    }

    private function findDebateByRoom(string $roomName): ?Debate
    {
        return Debate::where('livekit_room_name', $roomName)
            ->orWhere('prop_room_name', $roomName)
            ->orWhere('opp_room_name', $roomName)
            ->orWhere('result_room_name', $roomName)
            ->first();
    }

    /**
     * Verify the LiveKit webhook signature (Authorization: Bearer {jwt}).
     *
     * LiveKit signs webhooks with a JWT using the API secret. We verify by
     * checking the token can be decoded with the known secret, and that the
     * sha256 hash of the body embedded in the token matches the actual body.
     *
     * // TODO: SDK feature — use Agence104\LiveKit\WebhookReceiver once the
     * // PHP SDK exposes it. For now we do a lightweight JWT decode + body hash check.
     */
    private function verifySignature(string $body, string $authHeader): bool
    {
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $jwt    = substr($authHeader, 7);
        $secret = config('services.livekit.secret');

        if (empty($secret)) {
            Log::warning('LiveKit webhook: LIVEKIT_API_SECRET not configured — skipping verification.');
            return true;
        }

        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return false;
            }

            $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
            $tokenPayload = json_decode($payloadJson, true);

            if (empty($tokenPayload['sha256'])) {
                return false;
            }

            $expectedHash = $tokenPayload['sha256'];
            $actualHash   = base64_encode(hash('sha256', $body, true));

            if (! hash_equals($expectedHash, $actualHash)) {
                return false;
            }

            // Verify HMAC signature of the JWT itself.
            $sigInput  = $parts[0] . '.' . $parts[1];
            $expected  = rtrim(strtr(base64_encode(hash_hmac('sha256', $sigInput, $secret, true)), '+/', '-_'), '=');

            return hash_equals($expected, $parts[2]);
        } catch (\Throwable $e) {
            Log::error('LiveKit webhook signature verification failed: ' . $e->getMessage());
            return false;
        }
    }
}
