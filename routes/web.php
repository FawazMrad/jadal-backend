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
| Guest mode §1.2/§1.3. This is the path the App Links (Android) and
| Universal Links (iOS) association files will claim, so on a device with the
| app installed the OS intercepts the URL and this handler is never reached.
| It exists purely so a tapped link does not 404 in a plain browser.
|
| Deliberately minimal, and deliberately does NOT look the debate up:
|
|   - Nothing about the debate is rendered, so this leaks nothing to an
|     unauthenticated visitor (the guest API projection is the ONLY guest
|     read path, and it enforces the §Q4 access window).
|   - Every id renders identically, so this is not an enumeration oracle
|     telling a stranger which debate ids exist.
|
| Not a real landing page — replace with a proper one (or a store redirect)
| whenever product wants it.
|
*/
Route::get('/d/{id}', function (string $id) {
    return response()
        ->view('debate-link', ['debateId' => (int) $id])
        ->header('Cache-Control', 'public, max-age=300');
})->where('id', '[0-9]+')->name('debate.share-link');
