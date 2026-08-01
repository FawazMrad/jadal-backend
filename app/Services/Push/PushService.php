<?php

namespace App\Services\Push;

use App\Models\Device;
use App\Notifications\PushType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications through FCM HTTP v1 (frontend spec §7).
 *
 * Design notes:
 *  - Localization is PER DEVICE, not per user: a user with an Arabic phone and
 *    an English tablet gets each in its own language, using the `locale` stored
 *    at registration.
 *  - FCM data payloads are string-only, so every value is cast to a string.
 *  - If no service account is configured the whole thing degrades to a logged
 *    no-op instead of throwing. Push must NEVER break the business action that
 *    triggered it — a debate should still advance if FCM is down or unset.
 *  - Tokens FCM reports as UNREGISTERED/INVALID_ARGUMENT are pruned, which is
 *    the only way the table stays clean over time.
 */
class PushService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const HTTP_TIMEOUT = 10;

    /** Send one notification type to a set of users. */
    public function sendToUsers(iterable $userIds, string $type, array $data = [], array $replacements = []): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $devices = Device::whereIn('user_id', $ids)->get();

        $this->sendToDevices($devices, $type, $data, $replacements);
    }

    /** Send to an explicit device set (already resolved). */
    public function sendToDevices(Collection $devices, string $type, array $data = [], array $replacements = []): void
    {
        if ($devices->isEmpty()) {
            return;
        }

        if (! $this->isConfigured()) {
            Log::info('Push skipped — FCM is not configured', [
                'type'    => $type,
                'devices' => $devices->count(),
            ]);

            return;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return; // already logged
        }

        foreach ($devices as $device) {
            $this->sendOne($device, $type, $data, $replacements, $accessToken);
        }
    }

    private function sendOne(Device $device, string $type, array $data, array $replacements, string $accessToken): void
    {
        $copy = PushType::copy($type, $device->locale, $replacements);

        // FCM requires every data value to be a string.
        $stringData = ['type' => $type];
        foreach ($data as $key => $value) {
            $stringData[$key] = (string) $value;
        }

        $projectId = (string) config('services.fcm.project_id');

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token'        => $device->token,
                        'notification' => ['title' => $copy['title'], 'body' => $copy['body']],
                        'data'         => $stringData,
                    ],
                ]);

            if ($response->successful()) {
                return;
            }

            $reason = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));

            // The device is gone or the token is malformed — drop it so we stop
            // paying for it on every future send.
            if (in_array($reason, ['UNREGISTERED', 'INVALID_ARGUMENT'], true) || $response->status() === 404) {
                Log::info('Push token pruned', ['device_id' => $device->id, 'reason' => $reason ?: $response->status()]);
                $device->delete();

                return;
            }

            Log::warning('Push send failed', [
                'type'      => $type,
                'device_id' => $device->id,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
        } catch (\Throwable $e) {
            // Never let a delivery problem propagate into the caller's flow.
            Log::error('Push send threw', [
                'type'      => $type,
                'device_id' => $device->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.fcm.project_id'))
            && ! empty(config('services.fcm.credentials'));
    }

    /**
     * OAuth2 access token for the service account, cached just under Google's
     * one-hour lifetime so we are not minting a JWT per notification.
     */
    private function accessToken(): ?string
    {
        return cache()->remember('fcm_access_token', 3300, function (): ?string {
            $path = (string) config('services.fcm.credentials');

            if (! is_file($path)) {
                Log::error('FCM credentials file not found', ['path' => $path]);

                return null;
            }

            $creds = json_decode((string) file_get_contents($path), true);
            if (! is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
                Log::error('FCM credentials file is malformed', ['path' => $path]);

                return null;
            }

            $now = time();
            $jwt = $this->signJwt([
                'iss'   => $creds['client_email'],
                'scope' => self::FCM_SCOPE,
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $creds['private_key']);

            try {
                $response = Http::timeout(self::HTTP_TIMEOUT)
                    ->asForm()
                    ->post('https://oauth2.googleapis.com/token', [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion'  => $jwt,
                    ]);

                if (! $response->successful()) {
                    Log::error('FCM token exchange failed', ['status' => $response->status(), 'body' => $response->body()]);

                    return null;
                }

                return $response->json('access_token');
            } catch (\Throwable $e) {
                Log::error('FCM token exchange threw', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /** RS256-sign the service-account assertion (openssl is in the base image). */
    private function signJwt(array $claims, string $privateKey): string
    {
        $encode = fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $signingInput = $encode(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $encode($claims);

        $signature = '';
        openssl_sign($signingInput, $signature, $privateKey, 'sha256WithRSAEncryption');

        return $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
