<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Jadal Platform — API Routes
|--------------------------------------------------------------------------
|
| All routes use the Sanctum token-based authentication guard.
| Middleware aliases registered in bootstrap/app.php:
|   check.status  → CheckUserStatus (rejects suspended/banned users)
|   role          → RoleMiddleware   (usage: role:admin  or  role:trainer,admin)
|
*/

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::prefix('auth')->name('auth.')->group(function (): void {

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login');

    Route::post('login/google', [AuthController::class, 'googleLogin'])
        ->middleware('throttle:5,1')
        ->name('login.google');

    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1')
        ->name('password.forgot');

    Route::post('password/reset', [AuthController::class, 'resetPassword'])
        ->name('password.reset');

    // Requires authentication
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])
            ->name('logout');
    });
});

// ── Authenticated routes shared base ────────────────────────────────────────
Route::middleware(['auth:sanctum', 'check.status'])->group(function (): void {

    // ── Profile (FR-3) ───────────────────────────────────────────────────────
    Route::prefix('profile')->name('profile.')->group(function (): void {
        Route::get('/',         [ProfileController::class, 'show'])         ->name('show');
        Route::put('/',         [ProfileController::class, 'update'])       ->name('update');
        Route::post('/avatar',  [ProfileController::class, 'uploadAvatar']) ->name('avatar');
        Route::put('/password', [ProfileController::class, 'changePassword'])->name('password');
    });

    // ── Teams: leave (any authenticated user — FR-22) ────────────────────────
    // Must be declared BEFORE the trainer-only team group to avoid middleware clash
    Route::post('/teams/{team}/leave', [TeamController::class, 'leave'])
        ->name('teams.leave');

    // ── Teams: trainer-only management (FR-27, FR-29) ────────────────────────
    Route::middleware('role:trainer')->prefix('teams')->name('teams.')->group(function (): void {
        Route::get('/',    [TeamController::class, 'index'])  ->name('index');
        Route::post('/',   [TeamController::class, 'store'])  ->name('store');
        Route::get('/{team}',    [TeamController::class, 'show'])    ->name('show');
        Route::put('/{team}',    [TeamController::class, 'update'])  ->name('update');
        Route::delete('/{team}', [TeamController::class, 'destroy']) ->name('destroy');

        // Member management
        Route::post('/{team}/members',                    [TeamController::class, 'addMembers'])      ->name('members.add');
        Route::delete('/{team}/members/{user}',           [TeamController::class, 'removeMember'])    ->name('members.remove');
        Route::put('/{team}/members/priority',            [TeamController::class, 'reorderPriority']) ->name('members.priority');
    });

    // ── Admin: user management (FR-45 → FR-48) ───────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users',                        [AdminUserController::class, 'index'])        ->name('users.index');
        Route::post('/users',                       [AdminUserController::class, 'store'])        ->name('users.store');
        Route::get('/users/{user}',                 [AdminUserController::class, 'show'])         ->name('users.show');
        Route::put('/users/{user}',                 [AdminUserController::class, 'update'])       ->name('users.update');
        Route::patch('/users/{user}/status',        [AdminUserController::class, 'updateStatus']) ->name('users.status');
        Route::delete('/users/{user}',              [AdminUserController::class, 'destroy'])      ->name('users.destroy');
    });
});
