<?php

namespace Tests\Unit\Services\LiveKit;

use App\Services\LiveKit\EgressServiceClientWithTimeout;
use App\Services\LiveKit\RoomServiceClientWithTimeout;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The vendored LiveKit SDK's default Twirp HTTP client has no request
 * timeout, which let a stuck LiveKit server hang next-stage until
 * PHP-FPM/Nginx killed it. These prove the timeout-aware subclasses actually
 * wire a Guzzle client carrying the configured timeout into the Twirp client.
 */
class TimeoutAwareClientsTest extends TestCase
{
    public function test_egress_client_configures_guzzle_timeout(): void
    {
        $client = new EgressServiceClientWithTimeout('http://localhost:7880', 'key', 'secret', 6.5);

        $httpClient = $this->extractHttpClient($client);

        $this->assertSame(6.5, $httpClient->getConfig('timeout'));
        $this->assertSame(6.5, $httpClient->getConfig('connect_timeout'));
    }

    public function test_room_client_configures_guzzle_timeout(): void
    {
        $client = new RoomServiceClientWithTimeout('http://localhost:7880', 'key', 'secret', 6.5);

        $httpClient = $this->extractHttpClient($client);

        $this->assertSame(6.5, $httpClient->getConfig('timeout'));
        $this->assertSame(6.5, $httpClient->getConfig('connect_timeout'));
    }

    private function extractHttpClient(object $wrapper): GuzzleClient
    {
        $wrapperRef = new ReflectionClass($wrapper);
        $rpcProp = $wrapperRef->getProperty('rpc');
        $rpcProp->setAccessible(true);
        $rpc = $rpcProp->getValue($wrapper);

        $rpcRef = new ReflectionClass($rpc);
        $httpClientProp = $rpcRef->getProperty('httpClient');
        $httpClientProp->setAccessible(true);

        return $httpClientProp->getValue($rpc);
    }
}
