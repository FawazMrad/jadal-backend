<?php

namespace Tests\Feature;

use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveKitWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'APItestkey';
    // firebase/php-jwt v7 requires HS256 keys >= 32 bytes (real LiveKit secrets are).
    private const SECRET = 'livekit-test-secret-0123456789-abcdefgh';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.livekit.key' => self::KEY, 'services.livekit.secret' => self::SECRET]);
    }

    /**
     * Build a webhook JWT exactly the way LiveKit's server does: an HS256 JWT
     * signed with the API secret, carrying `iss` = API key and `sha256` =
     * base64(sha256(rawBody)).
     */
    private function signedToken(string $body, string $key = self::KEY, string $secret = self::SECRET): string
    {
        return JWT::encode([
            'iss'    => $key,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], $secret, 'HS256');
    }

    private function postWebhook(string $body, ?string $authHeader): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($authHeader !== null) {
            $server['HTTP_AUTHORIZATION'] = $authHeader;
        }

        return $this->call('POST', '/api/livekit/webhook', [], [], [], $server, $body);
    }

    public function test_valid_livekit_webhook_is_accepted_without_bearer_prefix(): void
    {
        $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main', 'sid' => 'RM_x']]);
        // LiveKit sends the RAW token as the Authorization value — no "Bearer ".
        $response = $this->postWebhook($body, $this->signedToken($body));

        $response->assertStatus(200);
    }

    public function test_accepts_token_even_with_accidental_bearer_prefix(): void
    {
        $body = json_encode(['event' => 'room_started', 'room' => ['name' => 'debate-y-main']]);
        $response = $this->postWebhook($body, 'Bearer ' . $this->signedToken($body));

        $response->assertStatus(200);
    }

    public function test_wrong_secret_is_rejected_401(): void
    {
        $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main']]);
        // Token signed with a DIFFERENT secret → HMAC signature mismatch.
        $token = $this->signedToken($body, self::KEY, 'a-totally-different-secret-0123456789-xyz');

        $this->postWebhook($body, $token)->assertStatus(401);
    }

    public function test_tampered_body_is_rejected_401(): void
    {
        $signedBody = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main']]);
        $token = $this->signedToken($signedBody); // sha256 of the ORIGINAL body

        // Deliver a different body than the one the token's sha256 claim covers.
        $tamperedBody = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-HIJACKED-main']]);

        $this->postWebhook($tamperedBody, $token)->assertStatus(401);
    }

    public function test_wrong_issuer_is_rejected_401(): void
    {
        $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main']]);
        // Correct secret but wrong issuer (api key) → fromJwt rejects.
        $token = $this->signedToken($body, 'WRONG-api-key', self::SECRET);

        $this->postWebhook($body, $token)->assertStatus(401);
    }

    public function test_missing_authorization_header_is_rejected_401(): void
    {
        $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main']]);
        $this->postWebhook($body, null)->assertStatus(401);
    }

    public function test_garbage_token_is_rejected_401(): void
    {
        $body = json_encode(['event' => 'room_finished', 'room' => ['name' => 'debate-x-main']]);
        $this->postWebhook($body, 'not-a-jwt-at-all')->assertStatus(401);
    }
}
