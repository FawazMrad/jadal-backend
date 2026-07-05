<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'livekit' => [
        'url'    => env('LIVEKIT_URL'),                              // wss:// — returned to clients
        'host'   => env('LIVEKIT_HOST', 'http://localhost:7880'),    // http(s):// — used by backend
        'key'    => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),
        // Path INSIDE the egress container, not the host. The egress container
        // mounts host /var/recordings at /out, so files must be written to /out
        // (writing to /var/recordings inside the container → permission denied).
        'egress_output_dir' => env('LIVEKIT_EGRESS_OUTPUT_DIR', '/out'),
        // Request timeout (seconds) for backend → LiveKit Twirp calls (room +
        // egress). The SDK's default HTTP client has NO timeout, so a stuck
        // LiveKit server previously hung until PHP-FPM/Nginx killed the request.
        'http_timeout' => env('LIVEKIT_HTTP_TIMEOUT', 8),
    ],

    // Sprinkles §9 — support contact shown in the app's nav drawer. Rides on the
    // login response so the mobile app never needs a second round-trip, and is
    // editable via env without an app release.
    'support' => [
        'email'     => env('SUPPORT_EMAIL', 'support@jadal.app'),
        'phone'     => env('SUPPORT_PHONE'),
        'instagram' => env('SUPPORT_INSTAGRAM'),
    ],

];
