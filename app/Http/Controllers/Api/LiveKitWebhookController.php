<?php

namespace App\Http\Controllers\Api;

use Agence104\LiveKit\AccessToken;
use App\Http\Controllers\Controller;
use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebatePhase;
use App\Models\DebateViewer;
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
            // V2 §7 — a non-participant joining the MAIN room is a viewer. No
            // sticky-stamp treatment needed (no "missed viewing" penalty to be
            // fair about) — just a lightweight join record, once per debate.
            if ($debate && $roomName === $debate->livekit_room_name) {
                DebateViewer::insertOrIgnore([
                    'debate_id' => $debate->id,
                    'user_id'   => $userId,
                    'viewed_at' => now(),
                ]);
            }

            return;
        }

        $isMainOrResult = in_array(
            $roomName,
            [$debate->livekit_room_name, $debate->result_room_name],
            true
        );
        $isPrep = in_array(
            $roomName,
            [$debate->prop_room_name, $debate->opp_room_name],
            true
        );

        // is_attended is LIVE presence (cleared again on leave). The *_at stamps
        // are the sticky historical record driving the §6.5 attendance stats —
        // set once on first join, never cleared.
        $updates = ['is_attended' => true];
        if ($isMainOrResult && $participant->first_attended_at === null) {
            $updates['first_attended_at'] = now();
        }
        if ($isPrep && $participant->prep_attended_at === null) {
            $updates['prep_attended_at'] = now();
        }
        $participant->update($updates);

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

        // Mark as no longer present so attendance-driven logic reflects who is
        // ACTUALLY in a room. (This also fixes a latent bug: chair election filters
        // on is_attended, so without clearing it a judge who already left could be
        // wrongly (re-)elected chair.)
        if ($participant) {
            $participant->update(['is_attended' => false]);
        }

        // If the leaver was the chair and the debate is still live (which now
        // includes the whole result phase), re-elect among the remaining judges —
        // whether they left the main room or the result room.
        if ($participant && $participant->is_chair && $debate->status === 'live') {
            $this->electChair($debate, excludeUserId: $userId);
        }

        // V11 §0 — when the last judge leaves the MAIN room during an active
        // speech, auto-pause the timer. The server owns this because once no judge
        // is present there is no client authority left to trigger it.
        if ($roomName === $debate->livekit_room_name) {
            $this->autoPauseTimerIfNoJudge($debate->fresh());
        }
    }

    /**
     * Pause the server-authoritative timer when no judge remains attended during
     * an active speech. Idempotent; stays paused until the chair resumes. During
     * an active speech the result room isn't open yet, so an attended judge is
     * necessarily one in the main room.
     */
    private function autoPauseTimerIfNoJudge(?Debate $debate): void
    {
        if (! $debate || $debate->status !== 'live') {
            return;
        }
        if ($debate->current_stage < 1 || $debate->isInResultPhase() || $debate->timer_is_paused) {
            return;
        }

        $judgePresent = DebateParticipant::where('debate_id', $debate->id)
            ->where('role', 'judge')
            ->where('status', 'approved')
            ->where('is_attended', true)
            ->exists();

        if ($judgePresent) {
            return;
        }

        $phase = DebatePhase::where('debate_id', $debate->id)
            ->where('order_index', $debate->current_stage)
            ->first();

        if (! $phase || $phase->started_at === null) {
            return;
        }

        $elapsed = max(0, now()->getTimestamp() - $phase->started_at->getTimestamp());
        $debate->update([
            'timer_is_paused'              => true,
            'timer_paused_elapsed_seconds' => $elapsed,
        ]);

        try {
            $this->liveKit->sendDataToRoom(
                $debate->livekit_room_name,
                [
                    'event'                        => 'timer_update',
                    'current_stage'                => $debate->current_stage,
                    'current_stage_started_at'     => $phase->started_at?->toIso8601String(),
                    'timer_is_paused'              => true,
                    'timer_paused_elapsed_seconds' => $elapsed,
                    'server_now'                   => now()->toIso8601String(),
                    'reason'                       => 'no_judge_present',
                ]
            );
        } catch (\Throwable) {}
    }

    /**
     * LiveKit's egress_ended webhook — sets debate_phases.audio_url and kicks
     * off transcription.
     *
     * PAYLOAD SHAPE (verified against the vendored protobuf definitions, which
     * are generated from LiveKit's own .proto and are therefore authoritative):
     *   WebhookEvent.egress_info          → field  9  (Livekit\WebhookEvent)
     *     EgressInfo.egress_id            → field  1  (Livekit\EgressInfo)
     *     EgressInfo.status               → field  3
     *     EgressInfo.file_results         → field 16  (repeated Livekit\FileInfo)
     *       FileInfo.filename             → field  1  — local path, e.g. /out/113/stage-1-30.mp3
     *       FileInfo.location             → field  5  — upload location (cloud storage)
     *
     * This method previously read `$payload['egress_id']` and
     * `$payload['file_results']` from the TOP level of the payload, where
     * LiveKit never puts them — so $egressId was always null and the method
     * silently returned at its first guard on every single webhook. That is
     * exactly why production saw 200 OK responses, LiveKit logged "sent
     * webhook", and yet audio_url stayed NULL with zero trace in laravel.log.
     *
     * It also looked for `outputs` and `download_url`, neither of which exists
     * anywhere in the schema — `file_results` and `filename`/`location` are the
     * real field names.
     *
     * Both snake_case and lowerCamelCase spellings are accepted at every level:
     * protojson emits lowerCamelCase by default but LiveKit pins
     * `UseProtoNames` in places, and it varies by version. Accepting both costs
     * nothing and makes this immune to that difference.
     */
    private function onEgressEnded(array $payload): void
    {
        $info = $payload['egress_info'] ?? $payload['egressInfo'] ?? [];

        // Fall back to the top level too — harmless, and covers any proxy or
        // future version that flattens the envelope.
        $egressId = $this->firstFilled($info, ['egress_id', 'egressId'])
            ?? $this->firstFilled($payload, ['egress_id', 'egressId']);

        $status = $this->firstFilled($info, ['status']);

        Log::info('LiveKit egress_ended received', [
            'egress_id' => $egressId,
            'status'    => $status,
            'payload'   => $payload,
        ]);

        if (! $egressId) {
            Log::warning('LiveKit egress_ended: no egress_id found in payload — cannot match a phase', [
                'payload_keys'      => array_keys($payload),
                'egress_info_keys'  => is_array($info) ? array_keys($info) : null,
                'payload'           => $payload,
            ]);

            return;
        }

        $phase = DebatePhase::where('egress_id', $egressId)->first();
        if (! $phase) {
            Log::warning('LiveKit egress_ended: no debate_phases row matches this egress_id', [
                'searched_egress_id' => $egressId,
                'hint'               => 'Compare against: SELECT id, debate_id, egress_id FROM debate_phases WHERE egress_id IS NOT NULL;',
            ]);

            return;
        }

        $fileResults = $info['file_results'] ?? $info['fileResults']
            ?? $payload['file_results'] ?? $payload['fileResults'] ?? [];

        $audioUrl = null;
        foreach ($fileResults as $result) {
            // filename is the local path this deployment records to (egress
            // writes into the bind-mounted /out); location is the cloud-upload
            // equivalent, used only if filename is absent.
            $audioUrl = $this->firstFilled($result, ['filename', 'location']);
            if ($audioUrl) {
                break;
            }
        }

        if (! $audioUrl) {
            Log::warning('LiveKit egress_ended: matched phase but no file path in the payload', [
                'egress_id'    => $egressId,
                'phase_id'     => $phase->id,
                'debate_id'    => $phase->debate_id,
                'status'       => $status,
                'file_results' => $fileResults,
            ]);

            return;
        }

        $phase->update(['audio_url' => $audioUrl]);

        Log::info('LiveKit egress_ended: audio_url saved', [
            'egress_id' => $egressId,
            'phase_id'  => $phase->id,
            'debate_id' => $phase->debate_id,
            'audio_url' => $audioUrl,
        ]);

        app(\App\Services\TranscriptionService::class)->dispatchBackgroundTranscription($phase);
    }

    /** First non-empty value among $keys, or null. */
    private function firstFilled(mixed $source, array $keys): mixed
    {
        if (! is_array($source)) {
            return null;
        }

        foreach ($keys as $key) {
            if (! empty($source[$key])) {
                return $source[$key];
            }
        }

        return null;
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
