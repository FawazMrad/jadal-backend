<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Debate share link — GET /d/{id}
|--------------------------------------------------------------------------
|
| Guest mode/ This is the path the App Links (Android) and
| Universal Links (iOS) association files will claim, so on a device with the
| app installed the OS intercepts the URL and this handler is never reached.
| It exists purely so a tapped link does not 404 in a plain browser.
|
| Deliberately minimal, and deliberately does NOT look the debate up:
|
|   - Nothing about the debate is rendered, so this leaks nothing to an
|     unauthenticated visitor (the guest API projection is the ONLY guest
| read path, and it enforces the access window).
|   - Every id renders identically, so this is not an enumeration oracle
|     telling a stranger which debate ids exist.
|
| Not a real landing page — replace with a proper one (or a store redirect)
| whenever product wants it.
|
*/
Route::get('/d/{id}', function (string $id) {
    $base = rtrim((string) (config('app.frontend_share_base_url') ?: config('app.url')), '/');
    $url  = "{$base}/d/{$id}";

    // Android intent:// URI — opens the app DIRECTLY, without relying on App
    // Links domain verification. This is the path that works inside WhatsApp /
    // Instagram / Telegram in-app browsers, which routinely ignore verified
    // App Links, and during the verification lag right after install.
    //
    // The host+path is carried without a scheme (`intent://host/path`), with
    // the real scheme supplied by the `scheme=` parameter. browser_fallback_url
    // must be percent-encoded because `;` and `&` terminate intent parameters.
    $intentUri = 'intent://' . preg_replace('#^https?://#', '', $url)
        . '#Intent;scheme=' . (parse_url($url, PHP_URL_SCHEME) ?: 'https')
        . ';package=' . config('app.android_package')
        . ';S.browser_fallback_url=' . rawurlencode($url)
        . ';end';

    return response()
        ->view('debate-link', [
            'debateId'      => (int) $id,
            'intentUri'     => $intentUri,
            'androidStore'  => config('app.android_store_url'),
            'iosStore'      => config('app.ios_store_url'),
        ])
        // Safe to cache: the page is byte-identical for every visitor on a
        // given id (no debate lookup, no UA-varying server-side branching —
        // platform detection happens client-side), so no Vary header needed.
        ->header('Cache-Control', 'public, max-age=300');
})->where('id', '[0-9]+')->name('debate.share-link');

/*
|--------------------------------------------------------------------------
| App Links association file — GET /.well-known/assetlinks.json
|--------------------------------------------------------------------------
|
| A SAFETY NET, not the primary delivery path.
|
| The real file lives at public/.well-known/assetlinks.json and is normally
| served straight off disk by the webserver: public/.htaccess only rewrites to
| index.php when `!-f`, so an existing file never reaches Laravel at all. This
| route therefore does not fire in the healthy case.
|
| It exists because the failure mode is SILENT and blocking — if the file is
| ever missing from a deploy (rsync/zip steps that skip dot-directories are a
| classic), Android verification fails with no error anywhere in our logs and
| every share link quietly opens a browser instead of the app.
|
| It reads the SAME file rather than duplicating the JSON, so there is exactly
| one place to append a fingerprint. It also guarantees `application/json`
| regardless of webserver mime config, and 404s honestly if the file is absent
| rather than inventing a response.
|
*/
Route::get('/.well-known/assetlinks.json', function () {
    $path = public_path('.well-known/assetlinks.json');

    abort_unless(is_file($path), 404);

    return response()
        ->file($path, [
            'Content-Type'  => 'application/json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
})->name('wellknown.assetlinks');
