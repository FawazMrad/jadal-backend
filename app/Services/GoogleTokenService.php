<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleTokenService
{
    /**
     * Verify a Google ID token and return the associated email address.
     *
     * @throws \RuntimeException if the token is invalid or expired
     */
    public function getEmailFromToken(string $idToken): string
    {
        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('رمز Google غير صالح. | Invalid Google token.');
        }

        $payload = $response->json();

        // Validate token is not expired
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('انتهت صلاحية رمز Google. | Google token has expired.');
        }

        // Validate audience matches our client ID (if configured)
        $clientId = config('services.google.client_id');
        if ($clientId && isset($payload['aud']) && $payload['aud'] !== $clientId) {
            throw new \RuntimeException('رمز Google غير مخصص لهذا التطبيق. | Google token audience mismatch.');
        }

        if (empty($payload['email'])) {
            throw new \RuntimeException('لم يتم العثور على البريد الإلكتروني في رمز Google. | Email not found in Google token.');
        }

        return $payload['email'];
    }
}
