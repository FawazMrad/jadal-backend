<?php

namespace App\Http\Controllers\Api;

use Agence104\LiveKit\AccessToken;
use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Services\LiveKitService;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
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

        $isMainOrResult = in_array(
            $roomName,
            [$debate->livekit_room_name, $debate->result_room_name],
            true
        );

        // Chair election runs on judge presence in the main room AND the result
        // room — judges deliberate in the result room (B3). The highest-ranked
        // attended judge is (re-)elected and chair_elected is broadcast to both.
        if ($isMainOrResult && $participant->role === 'judge') {
            $this->electChair($debate);
        }

        // Broadcast attendance to whichever room the participant joined.
        if ($isMainOrResult) {
            try {
                $this->liveKit->sendDataToRoom(
                    $roomName,
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
        if (! $debate || ! in_array($roomName, [$debate->livekit_room_name, $debate->result_room_name], true)) {
            return;
        }

        $userId      = (int) $identity;
        $participant = DebateParticipant::where('debate_id', $debate->id)
            ->where('user_id', $userId)
            ->first();

        // If the leaver was the chair and the debate is still live (which now
        // includes the whole result phase), re-elect among the remaining judges —
        // whether they left the main room or the result room.
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
        // Remember the outgoing chair so we only broadcast on an actual change.
        $previousChairUserId = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->where('is_chair', true)
            ->value('user_id');

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

            // Broadcast only when the chair actually changed. Send to BOTH the main
            // and result rooms so the new chair gains moderation authority wherever
            // judges are present (deliberation happens in the result room — B3/B5).
            if ((int) $newChair->user_id !== (int) $previousChairUserId) {
                foreach ([$debate->livekit_room_name, $debate->result_room_name] as $room) {
                    if (! $room) {
                        continue;
                    }
                    try {
                        $this->liveKit->sendDataToRoom(
                            $room,
                            ['event' => 'chair_elected', 'chair_user_id' => (int) $newChair->user_id]
                        );
                    } catch (\Throwable) {}
                }
            }
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
     * Verify a LiveKit webhook using the official SDK verification path.
     *
     * LiveKit sends the signed JWT as the *raw* `Authorization` header value —
     * there is NO "Bearer " scheme. Verification (identical to what
     * Agence104\LiveKit\WebhookReceiver::receive() performs) is:
     *   1. Decode the HS256 JWT with the API secret (firebase/php-jwt, via the
     *      SDK's AccessToken::fromJwt) — this also enforces `iss == apiKey`.
     *   2. Confirm the token's `sha256` claim equals base64(sha256(rawBody)).
     *
     * The previous DIY implementation rejected every real webhook because it
     * required an "Authorization: Bearer …" prefix that LiveKit never sends —
     * it bailed out before any crypto ran (hence no exceptions, no logs).
     */
    private function verifySignature(string $body, string $authHeader): bool
    {
        // Tolerate (but never require) an accidental scheme prefix from proxies.
        $jwt = trim(preg_replace('/^Bearer\s+/i', '', $authHeader));

        $key    = config('services.livekit.key');
        $secret = config('services.livekit.secret');

        if (empty($key) || empty($secret)) {
            if (app()->environment('production')) {
                Log::error('LiveKit webhook REJECTED: LIVEKIT_API_KEY/SECRET not configured in production.');
                return false;
            }
            Log::warning('LiveKit webhook: API key/secret not configured — allowing in non-production only.');
            return true;
        }

        if ($jwt === '') {
            $this->logWebhookAuthFailure('missing_authorization_header', $authHeader);
            return false;
        }

        try {
            // SDK's official verify: HS256 decode + issuer check.
            $grants = (new AccessToken($key, $secret))->fromJwt($jwt);
        } catch (SignatureInvalidException $e) {
            $this->logWebhookAuthFailure('signature_mismatch — wrong API secret or tampered token', $authHeader, $jwt);
            return false;
        } catch (ExpiredException | BeforeValidException $e) {
            $this->logWebhookAuthFailure('token_expired_or_not_yet_valid: ' . $e->getMessage(), $authHeader, $jwt);
            return false;
        } catch (\Throwable $e) {
            // Malformed token, bad issuer, unexpected claim shape, etc.
            $this->logWebhookAuthFailure('malformed_or_invalid_jwt: ' . $e->getMessage(), $authHeader, $jwt);
            return false;
        }

        // Body-integrity claim: sha256 == base64(sha256(rawBody)).
        $expected = (string) $grants->getSha256();
        $actual   = base64_encode(hash('sha256', $body, true));

        if ($expected === '' || ! hash_equals($expected, $actual)) {
            $this->logWebhookAuthFailure('body_hash_mismatch (sha256 claim != base64(sha256(body)))', $authHeader, $jwt);
            return false;
        }

        return true;
    }

    /**
     * Log a verification failure with everything needed to diagnose webhook auth
     * issues without re-instrumenting: the reason, the raw Authorization header,
     * and the decoded JWT header.
     */
    private function logWebhookAuthFailure(string $reason, string $authHeader, ?string $jwt = null): void
    {
        $jwtHeader = null;
        if ($jwt) {
            $seg = explode('.', $jwt);
            if (isset($seg[0])) {
                $jwtHeader = json_decode(base64_decode(strtr($seg[0], '-_', '+/')) ?: '', true);
            }
        }

        Log::warning('LiveKit webhook signature verification failed', [
            'reason'               => $reason,
            'authorization_header' => $authHeader,
            'jwt_header'           => $jwtHeader,
        ]);
    }
}
