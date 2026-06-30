<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\EgressServiceClient;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\Debate;
use Illuminate\Support\Facades\Log;
use Livekit\EncodedFileOutput;

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
     */
    protected function roomServiceClient(): RoomServiceClient
    {
        return new RoomServiceClient($this->host, $this->apiKey, $this->apiSecret);
    }

    /**
     * Builds the LiveKit Egress service client (Twirp over the http(s) host),
     * same pattern as the room client. Overridable so tests can assert the
     * egress calls without a running LiveKit server.
     */
    protected function egressServiceClient(): EgressServiceClient
    {
        return new EgressServiceClient($this->host, $this->apiKey, $this->apiSecret);
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
        ?string $displayName = null
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
     * Start a ParticipantEgress recording the active speaker (by identity) to a
     * file, and return the egress ID.
     *
     * Routed through the SDK's EgressServiceClient (Twirp call to
     * /twirp/livekit.Egress/StartParticipantEgress over the http host). The
     * previous hand-rolled POST to /twirp/livekit.EgressService/StartTrackEgress
     * used a service name that does not exist, so it 404'd on every call.
     */
    public function startTrackEgressForParticipant(
        string $roomName,
        string $identity,
        int|string $debateId,
        int $stageOrder
    ): string {
        $outputDir = config('services.livekit.egress_output_dir', '/var/recordings');
        $filePath  = "{$outputDir}/{$debateId}/stage-{$stageOrder}-{$identity}.mp4";

        // .mp4 filepath → LiveKit encodes an MP4 EncodedFileOutput for it.
        $output = (new EncodedFileOutput())->setFilepath($filePath);

        $info = $this->egressServiceClient()->startParticipantEgress($roomName, $identity, $output);

        return $info->getEgressId();
    }

    public function stopEgress(string $egressId): void
    {
        $this->egressServiceClient()->stopEgress($egressId);
    }
}
