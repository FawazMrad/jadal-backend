<?php

namespace App\Services;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;

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

    public function createRoom(string $roomName, int $emptyTimeoutSeconds = 600): void
    {
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);

        $options = (new RoomCreateOptions())
            ->setName($roomName)
            ->setEmptyTimeout($emptyTimeoutSeconds);

        $client->createRoom($options);
    }

    public function deleteRoom(string $roomName): void
    {
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);
        $client->deleteRoom($roomName);
    }

    public function muteParticipant(string $roomName, string $identity, string $trackSid): void
    {
        $client = new RoomServiceClient($this->url, $this->apiKey, $this->apiSecret);
        $client->mutePublishedTrack($roomName, $identity, $trackSid, true);
    }
}
