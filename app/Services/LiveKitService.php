<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\Debate;

class LiveKitService
{
    private string $apiKey;
    private string $apiSecret;
    private string $url;

    public function __construct()
    {
        $this->url       = config('services.livekit.url');
        $this->apiKey    = config('services.livekit.key');
        $this->apiSecret = config('services.livekit.secret');
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
        bool $roomAdmin = false
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

        $token = (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($grant);

        return $token->toJwt();
    }

    // ── Room lifecycle ────────────────────────────────────────────────────────

    public function createRoom(string $roomName, int $emptyTimeoutSeconds = 600): void
    {
        $client  = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);
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
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);
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
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);
        $client->mutePublishedTrack($roomName, $identity, $trackSid, true);
    }

    /**
     * Broadcast a JSON payload to all participants in a room (or specific identities).
     *
     * // TODO: SDK feature — RoomServiceClient::sendData is available in
     * // agence104/livekit-server-sdk >= 1.4.0. If the installed version does
     * // not expose it, fall back to a direct Twirp HTTP call below.
     */
    public function sendDataToRoom(string $roomName, array $payload, ?array $destinationIdentities = null): void
    {
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);

        if (method_exists($client, 'sendData')) {
            // Positional call — SDK signature varies by version.
            $client->sendData($roomName, json_encode($payload), $destinationIdentities ?? []);
            return;
        }

        // Fallback: Twirp HTTP endpoint with a signed JWT.
        $this->sendDataViaTwirp($roomName, $payload, $destinationIdentities);
    }

    // ── Egress ────────────────────────────────────────────────────────────────

    /**
     * Start a TrackEgress for the active speaker and return the egress ID.
     *
     * // TODO: SDK feature — TrackEgress is not yet exposed in the PHP SDK.
     * // Using the Twirp HTTP endpoint with a signed JWT until SDK support lands.
     */
    public function startTrackEgressForParticipant(
        string $roomName,
        string $identity,
        int|string $debateId,
        int $stageOrder
    ): string {
        $outputDir = config('services.livekit.egress_output_dir', '/var/recordings');
        $filePath  = "{$outputDir}/{$debateId}/stage-{$stageOrder}-{$identity}.mp4";

        $body = [
            'room_name'    => $roomName,
            'participant'  => ['identity' => $identity],
            'file_outputs' => [
                ['file_type' => 1, 'filepath' => $filePath],
            ],
        ];

        $response = $this->twirpRequest('EgressService', 'StartTrackEgress', $body);

        return $response['egress_id'] ?? '';
    }

    public function stopEgress(string $egressId): void
    {
        $this->twirpRequest('EgressService', 'StopEgress', ['egress_id' => $egressId]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function sendDataViaTwirp(string $roomName, array $payload, ?array $destinationIdentities): void
    {
        $body = [
            'room'          => $roomName,
            'data'          => base64_encode(json_encode($payload)),
            'kind'          => 1, // RELIABLE
            'destination_sids' => $destinationIdentities ?? [],
        ];

        $this->twirpRequest('RoomService', 'SendData', $body);
    }

    private function twirpRequest(string $service, string $method, array $body): array
    {
        $jwt = $this->generateServerJwt();

        $ch = curl_init("{$this->url}/twirp/livekit.{$service}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$jwt}",
            ],
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("LiveKit Twirp request failed: {$err}");
        }

        return json_decode($raw, true) ?? [];
    }

    private function generateServerJwt(): string
    {
        $grant = new VideoGrant();
        $grant->setRoomCreate(true);
        $grant->setRoomList(true);

        $tokenOptions = (new AccessTokenOptions())
            ->setIdentity('server')
            ->setTtl(60);

        $token = (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($tokenOptions)
            ->setGrant($grant);

        return $token->toJwt();
    }
}
