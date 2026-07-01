<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\EgressServiceClient;
use GuzzleHttp\Client as GuzzleClient;
use Livekit\EgressClient;

/**
 * Agence104\LiveKit\EgressServiceClient hardcodes its Twirp client with the
 * PSR-18 client found via discovery, which has no request timeout — Guzzle's
 * default is to wait forever. That let a stuck egress server hang next-stage
 * until PHP-FPM/Nginx killed the request. This subclass swaps in a Guzzle
 * client with an explicit timeout so a genuinely unreachable/stuck egress
 * server fails fast instead.
 */
class EgressServiceClientWithTimeout extends EgressServiceClient
{
    public function __construct(string $host, string $apiKey, string $apiSecret, float $timeoutSeconds)
    {
        parent::__construct($host, $apiKey, $apiSecret);

        $this->rpc = new EgressClient($host, new GuzzleClient([
            'timeout'         => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
        ]));
    }
}
