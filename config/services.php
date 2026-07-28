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

    'whisper' => [
        // Path to the Whisper CLI binary inside its Python venv on the host
        // running the transcription command. Differs per environment (home
        // directory), so it's env-driven rather than hardcoded.
        'binary_path' => env('WHISPER_BINARY_PATH', '/home/fawaz/whisper-venv/bin/whisper'),
        // Host filesystem base the egress container's /out is bind-mounted to
        // (see services.livekit.egress_output_dir) — used to resolve a
        // debate_phases.audio_url value like "/out/113/stage-1-30.mp3" into
        // the real path this PHP process can read from disk.
        'recordings_base_path' => env('RECORDINGS_BASE_PATH', '/var/recordings'),
        // Process timeouts (seconds) for the two shell-outs in the transcription
        // pipeline. Env-overridable so a production timeout can be tuned without
        // a code deploy; the effective value is logged whenever a step runs, so
        // laravel.log always proves which limit was actually in force.
        'ffmpeg_timeout' => (int) env('FFMPEG_TIMEOUT_SECONDS', 300),
        'timeout'        => (int) env('WHISPER_TIMEOUT_SECONDS', 1800),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model'   => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
    ],

];
