<?php

namespace Tests\Feature;

use Agence104\LiveKit\RoomServiceClient;
use App\Services\LiveKitService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mockery;
use Tests\TestCase;

/**
 * Test seam: override the SDK client factory so we can assert the exact
 * server-API call shape without hitting a real LiveKit server.
 */
class FakeLiveKitService extends LiveKitService
{
    public RoomServiceClient $client;

    protected function roomServiceClient(): RoomServiceClient
    {
        return $this->client;
    }
}

class LiveKitBroadcastTest extends TestCase
{
    private const SECRET = 'livekit-test-secret-0123456789-abcdefgh'; // >= 32 bytes for HS256

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.livekit.url'    => 'wss://livekit.example.com',
            'services.livekit.host'   => 'http://livekit.example.com:7880',
            'services.livekit.key'    => 'APItestkey',
            'services.livekit.secret' => self::SECRET,
        ]);
    }

    public function test_send_data_broadcasts_reliably_to_everyone(): void
    {
        $payload = ['event' => 'stage_changed', 'current_stage' => 3, 'speaker_user_id' => 11];

        $mock = Mockery::mock(RoomServiceClient::class);
        // The bug was passing [] as arg #3 (int $kind). Assert kind = 0 (RELIABLE)
        // and an empty identities list (arg #4) = broadcast to everyone.
        $mock->shouldReceive('sendData')
            ->once()
            ->with('debate-73-main', json_encode($payload), 0, []);

        $svc = new FakeLiveKitService();
        $svc->client = $mock;

        $svc->sendDataToRoom('debate-73-main', $payload);

        $this->assertTrue(true); // Mockery verifies the expectation on tearDown
    }

    public function test_send_data_failure_is_logged_not_thrown(): void
    {
        $mock = Mockery::mock(RoomServiceClient::class);
        $mock->shouldReceive('sendData')->andThrow(new \RuntimeException('connection refused'));

        $svc = new FakeLiveKitService();
        $svc->client = $mock;

        // Must NOT throw — a LiveKit hiccup cannot 500 a next-stage request.
        $svc->sendDataToRoom('debate-73-main', ['event' => 'stage_changed']);

        $this->assertTrue(true);
    }

    public function test_room_token_carries_display_name(): void
    {
        $svc = new LiveKitService();

        $token = $svc->generateRoomToken(
            'debate-73-main', '16', true, true, false, false, false, 'حصة الدوسري'
        );

        $decoded = JWT::decode($token, new Key(self::SECRET, 'HS256'));

        $this->assertEquals('16', $decoded->sub ?? $decoded->identity ?? null);
        $this->assertEquals('حصة الدوسري', $decoded->name);
    }

    public function test_room_token_without_name_omits_it(): void
    {
        $svc = new LiveKitService();
        $token = $svc->generateRoomToken('debate-73-main', '16', true, true, false);

        $decoded = JWT::decode($token, new Key(self::SECRET, 'HS256'));
        $this->assertTrue(! isset($decoded->name) || $decoded->name === '' || $decoded->name === null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
