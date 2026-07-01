<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\RoomServiceClient;
use GuzzleHttp\Client as GuzzleClient;
use Livekit\RoomServiceClient as LKRoomServiceClient;

/**
 * Same fix as EgressServiceClientWithTimeout, for the room service Twirp
 * client (used by mute/sendData/createRoom/deleteRoom/getParticipant).
 */
class RoomServiceClientWithTimeout extends RoomServiceClient
{
    public function __construct(string $host, string $apiKey, string $apiSecret, float $timeoutSeconds)
    {
        parent::__construct($host, $apiKey, $apiSecret);

        $this->rpc = new LKRoomServiceClient($host, new GuzzleClient([
            'timeout'         => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
        ]));
    }
}
