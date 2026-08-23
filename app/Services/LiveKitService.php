<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\EncodedOutputs;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\Debate;
use App\Services\LiveKit\EgressServiceClientWithTimeout;
use App\Services\LiveKit\RoomServiceClientWithTimeout;
use Illuminate\Support\Facades\Log;
use Livekit\EncodedFileOutput;
use Livekit\EncodedFileType;
use Livekit\TrackSource;

/**
 * Two URLs are used:
 * - config('services.livekit.url')   → wss:// → returned to clients in token responses
 * - config('services.livekit.host')  → http(s):// → used by backend for Twirp API calls
 *
 * They MUST NOT be mixed. cURL cannot speak wss://, so any backend HTTP
 * call to LiveKit must use the .host URL.
 */
class LiveKitService
{
    /** LiveKit DataPacket.Kind — RELIABLE = 0 (LOSSY = 1). Debate events must be reliable. */
    private const DATA_KIND_RELIABLE = 0;

    private string $apiKey;
    private string $apiSecret;
    private string $url;   // wss:// — client connection URL (not used for server API calls)
    private string $host;  // http(s):// — server-to-server Twirp/HTTP API base URL

    public function __construct()
    {
        $this->url       = config('services.livekit.url');
        $this->host      = config('services.livekit.host');
        $this->apiKey    = config('services.livekit.key');
        $this->apiSecret = config('services.livekit.secret');
    }

    /**
     * Builds the LiveKit RoomService client (Twirp over the http(s) host).
     * Overridable so tests can assert the exact server-API calls.
     *
     * Uses a timeout-aware subclass — the SDK's default HTTP client has no
     * request timeout, which previously let a stuck LiveKit server hang a
     * request until PHP-FPM/Nginx killed it.
     */
    protected function roomServiceClient(): RoomServiceClient
    {
        return new RoomServiceClientWithTimeout($this->host, $this->apiKey, $this->apiSecret, $this->httpTimeoutSeconds());
    }

    /**
     * Builds the LiveKit Egress service client (Twirp over the http(s) host),
     * same pattern as the room client. Overridable so tests can assert the
     * egress calls without a running LiveKit server.
     */
    protected function egressServiceClient(): EgressServiceClient
    {
        return new EgressServiceClientWithTimeout($this->host, $this->apiKey, $this->apiSecret, $this->httpTimeoutSeconds());
    }

    private function httpTimeoutSeconds(): float
    {
        return (float) config('services.livekit.http_timeout', 8);
    }

    // ── Token generation ──────────────────────────────────────────────────────

    public function generateToken(
        string $roomName,
        string $identity,
        string $role,
        bool $isChair = false
    ): string {
        $canPublish     = in_array($role, ['debater', 'trainer', 'judge']);
        $canSubscribe   = true;
        $canPublishData = $role === 'judge' && $isChair;

        $grant = new VideoGrant();
        $grant->setRoomJoin(true);
        $grant->setRoomName($roomName);
        $grant->setCanPublish($canPublish);
        $grant->setCanSubscribe($canSubscribe);
        $grant->setCanPublishData($canPublishData);

        $tokenOptions = (new AccessTokenOptions())
            ->setIdentity($identity)
            ->setTtl(7200); // 2 hours

        $token = (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($grant);

        return $token->toJwt();
    }

    /**
     * Generate a room-scoped token with explicit permission flags.
     * Used by the extended getToken endpoint which handles all 4 rooms.
     */
    public function generateRoomToken(
        string $roomName,
        string $identity,
        bool $canPublish,
        bool $canSubscribe,
        bool $canPublishData,
        bool $canUpdateOwnMetadata = false,
        bool $roomAdmin = false,
        ?string $displayName = null,
        bool $hidden = false
    ): string {
        $grant = new VideoGrant();
        $grant->setRoomJoin(true);
        $grant->setRoomName($roomName);
        $grant->setCanPublish($canPublish);
        $grant->setCanSubscribe($canSubscribe);
        $grant->setCanPublishData($canPublishData);

        // canUpdateOwnMetadata and roomAdmin are newer SDK features.
        // If the installed SDK version does not expose them, they are silently skipped.
        if ($canUpdateOwnMetadata && method_exists($grant, 'setCanUpdateOwnMetadata')) {
            $grant->setCanUpdateOwnMetadata(true);
        }
        if ($roomAdmin && method_exists($grant, 'setRoomAdmin')) {
            $grant->setRoomAdmin(true);
        }

        // TRUE invisibility, not a client-side cosmetic filter.
        //
        // `hidden` maps to LiveKit's ParticipantPermission.hidden (protobuf
        // field 7, "indicates that it's hidden to others"). The server omits
        // such a participant from every other participant's room state and
        // fires no participant-connected event for them — the same mechanism
        // LiveKit uses for recorder/egress participants. The guest still
        // subscribes normally; they simply are not in anyone else's roster.
        //
        // method_exists guard matches the pattern above: on an SDK too old to
        // expose it the flag is skipped rather than fatal — in which case
        // guests would become visible, so the guard is deliberately paired
        // with a hard assertion in the test suite.
        if ($hidden && method_exists($grant, 'setHidden')) {
            $grant->setHidden(true);
        }

        $tokenOptions = (new AccessTokenOptions())
            ->setIdentity($identity)
            ->setTtl(7200);

        // Display name → surfaces as Participant.name on every client (used for
        // labelling remote tiles). Identity stays the user id.
        if ($displayName !== null && $displayName !== '') {
            $tokenOptions->setName($displayName);
        }

        $token = (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($grant);

        return $token->toJwt();
    }

    // ── Room lifecycle ────────────────────────────────────────────────────────

    public function createRoom(string $roomName, int $emptyTimeoutSeconds = 600): void
    {
        $client  = $this->roomServiceClient();
        $options = (new RoomCreateOptions())
            ->setName($roomName)
            ->setEmptyTimeout($emptyTimeoutSeconds);
        $client->createRoom($options);
    }

    public function createRoomIfMissing(string $roomName, int $emptyTimeout = 600): void
    {
        try {
            $this->createRoom($roomName, $emptyTimeout);
        } catch (\Throwable $e) {
            // Swallow "room already exists" errors; re-throw anything unexpected.
            if (stripos($e->getMessage(), 'already exist') === false) {
                throw $e;
            }
        }
    }

    public function deleteRoom(string $roomName): void
    {
        $client = $this->roomServiceClient();
        $client->deleteRoom($roomName);
    }

    public function deleteRoomIfExists(string $roomName): void
    {
        try {
            $this->deleteRoom($roomName);
        } catch (\Throwable) {
            // Room may not exist — safe to ignore.
        }
    }

    /**
     * Assign deterministic room names to the four debate rooms and persist
     * them on the debate record. Does NOT create rooms on the LiveKit server.
     */
    public function provisionDebateRooms(Debate $debate): void
    {
        $updates = [];

        if (! $debate->prop_room_name) {
            $updates['prop_room_name'] = "debate-{$debate->id}-prop";
        }
        if (! $debate->opp_room_name) {
            $updates['opp_room_name'] = "debate-{$debate->id}-opp";
        }
        if (! $debate->result_room_name) {
            $updates['result_room_name'] = "debate-{$debate->id}-result";
        }
        // Ensure the main room name follows the same pattern if not already set.
        if (! $debate->livekit_room_name) {
            $updates['livekit_room_name'] = "debate-{$debate->id}-main";
        }

        if (! empty($updates)) {
            $debate->update($updates);
        }
    }

    // ── Participant control ───────────────────────────────────────────────────

    public function muteParticipant(string $roomName, string $identity, string $trackSid): void
    {
        $client = $this->roomServiceClient();
        $client->mutePublishedTrack($roomName, $identity, $trackSid, true);
    }

    /**
     * Broadcast a JSON payload to participants in a room over the LiveKit data
     * channel. With an empty $destinationIdentities the message is broadcast to
     * EVERYONE in the room.
     *
     * The SDK signature is
     *   sendData(string $room, string $data, int $kind, array $destinationIdentities = [], ?string $topic)
     * so $kind (RELIABLE = 0) MUST be the 3rd argument. A previous version passed
     * the identities array there, which threw a TypeError that the call sites
     * swallowed — so NO debate event ever reached any client. This is non-fatal
     * (a LiveKit hiccup must not 500 a next-stage request) but always logged.
     */
    public function sendDataToRoom(string $roomName, array $payload, ?array $destinationIdentities = null): void
    {
        $event = $payload['event'] ?? 'unknown';

        try {
            $this->roomServiceClient()->sendData(
                $roomName,
                json_encode($payload),
                self::DATA_KIND_RELIABLE,
                $destinationIdentities ?? [] // empty = broadcast to everyone in the room
            );

            Log::info('LiveKit sendData OK', [
                'room'      => $roomName,
                'event'     => $event,
                'broadcast' => empty($destinationIdentities),
            ]);
        } catch (\Throwable $e) {
            Log::error('LiveKit sendData FAILED', [
                'room'  => $roomName,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Egress ────────────────────────────────────────────────────────────────

    /**
     * Find the identity's published microphone track SID. Required by
     * startTrackCompositeEgress, which targets tracks by SID rather than
     * capturing a whole participant.
     */
    /**
     * Resolve the SID of a participant's microphone track, retrying briefly.
     *
     * Confirmed against LiveKit's own server logs: a debater can publish their
     * mic in the same second the chair calls next-stage, so the track can be
     * genuinely absent server-side at the moment of the first lookup. Failing
     * immediately turns that timing window into a lost stage recording, so the
     * lookup is retried a bounded number of times.
     *
     * Cost in the common case (mic already published) is ZERO added latency:
     * the loop returns on the first attempt and only ever sleeps AFTER a miss,
     * never after the final attempt. Worst case is (attempts - 1) * delay —
     * 1.6s at the defaults.
     *
     * NOTE: this only closes the millisecond-scale race. The frontend joins
     * every participant muted and publishes a mic track only on a manual tap,
     * so a speaker who has not yet unmuted is an UNBOUNDED wait that no retry
     * can cover — that case needs a gate before next-stage, not a longer
     * timeout here.
     */
    private function findMicrophoneTrackId(string $roomName, string $identity): string
    {
        // Floors guarantee the loop always runs at least once and never sleeps
        // a negative duration, even if these are misconfigured.
        $attempts = max(1, (int) config('services.livekit.mic_track_attempts', 5));
        $delayMs  = max(0, (int) config('services.livekit.mic_track_retry_delay_ms', 400));

        $context = ['room' => $roomName, 'identity' => $identity];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $participant = $this->roomServiceClient()->getParticipant($roomName, $identity);

            foreach ($participant->getTracks() as $track) {
                if ($track->getSource() !== TrackSource::MICROPHONE) {
                    continue;
                }

                if ($attempt === 1) {
                    Log::debug('Microphone track found on first attempt', $context);
                } else {
                    // Info, not debug: reaching here means the race is real and
                    // recurring in production, and that we recovered from it.
                    Log::info('Microphone track found after retrying', $context + [
                        'attempt'   => $attempt,
                        'waited_ms' => ($attempt - 1) * $delayMs,
                    ]);
                }

                return $track->getSid();
            }

            // Never sleep after the last attempt — that is dead time added to a
            // call that is about to throw anyway.
            if ($attempt < $attempts) {
                Log::debug('Microphone track not published yet — retrying', $context + [
                    'attempt'     => $attempt,
                    'of_attempts' => $attempts,
                    'retry_in_ms' => $delayMs,
                ]);

                usleep($delayMs * 1000);
            }
        }

        $waitedMs = ($attempts - 1) * $delayMs;

        Log::warning('Microphone track never appeared — giving up', $context + [
            'attempts'  => $attempts,
            'waited_ms' => $waitedMs,
        ]);

        throw new \RuntimeException(
            "No microphone audio track found for participant {$identity} in room {$roomName} "
            . "after {$attempts} attempts over ~{$waitedMs}ms."
        );
    }

    /**
     * Start an audio-only TrackCompositeEgress recording the active speaker's
     * microphone (by identity) to an .mp3, and return the egress ID.
     *
     * The platform only ever needs speech audio, never video — and video
     * muxing/finalization on stop is measurably slower than audio-only,
     * which was contributing to StopEgress timeouts. ParticipantEgressRequest
     * has no audio-only option, so this targets the mic track directly via
     * startTrackCompositeEgress with an empty video track id.
     *
     * Routed through the SDK's EgressServiceClient (Twirp call to
     * /twirp/livekit.Egress/StartTrackCompositeEgress over the http host).
     */
    public function startTrackEgressForParticipant(
        string $roomName,
        string $identity,
        int|string $debateId,
        int $stageOrder
    ): string {
        $outputDir = config('services.livekit.egress_output_dir', '/out');
        $filePath  = "{$outputDir}/{$debateId}/stage-{$stageOrder}-{$identity}.mp3";

        $audioTrackId = $this->findMicrophoneTrackId($roomName, $identity);

        // Wrapped in EncodedOutputs so the SDK emits only `file_outputs` —
        // TrackCompositeEgressRequest's singular `file` field is deprecated
        // and triggers an E_USER_DEPRECATED notice if set directly.
        $output = (new EncodedOutputs())->setFile(
            (new EncodedFileOutput())
                ->setFilepath($filePath)
                ->setFileType(EncodedFileType::MP3)
        );

        $info = $this->egressServiceClient()->startTrackCompositeEgress($roomName, $output, $audioTrackId, '');

        return $info->getEgressId();
    }

    /**
     * Stop an active egress. Stopping one that already reached a terminal state
     * (failed / complete / aborted) or was already cleaned up is a NO-OP, not an
     * error — those benign cases are swallowed so a chair mashing next-stage, or
     * a recording that already ended, doesn't spam ERROR logs. Genuine problems
     * (timeouts, auth, connectivity) are re-thrown for the caller to log.
     */
    public function stopEgress(string $egressId): void
    {
        try {
            $this->egressServiceClient()->stopEgress($egressId);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            $alreadyTerminal = str_contains($msg, 'not found')
                || str_contains($msg, 'cannot be stopped');

            if (! $alreadyTerminal) {
                throw $e; // real failure (e.g. "request timed out") — surface it
            }
        }
    }
}
