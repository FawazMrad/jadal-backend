<?php

use App\Http\Controllers\Api\Admin\AdminBlogController;
use App\Http\Controllers\Api\Admin\AdminSurveyController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\Trainer\TrainerSurveyController;
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

// ── Auth (public) ─────────────────────────────────────────────────────────────
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

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// ── Authenticated + active users ──────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'check.status'])->group(function (): void {

    // ── Profile ───────────────────────────────────────────────────────────────
    Route::prefix('profile')->name('profile.')->group(function (): void {
        Route::get('/',         [ProfileController::class, 'show'])          ->name('show');
        Route::put('/',         [ProfileController::class, 'update'])        ->name('update');
        Route::post('/avatar',  [ProfileController::class, 'uploadAvatar'])  ->name('avatar');
        Route::put('/password', [ProfileController::class, 'changePassword'])->name('password');
    });

    // ── Blog — public reading & reactions (any auth user) ─────────────────────
    Route::prefix('blog')->name('blog.')->group(function (): void {
        Route::get('/',                  [BlogController::class, 'index'])  ->name('index');
        Route::get('/{slug}',            [BlogController::class, 'show'])   ->name('show')
            ->where('slug', '[a-zA-Z0-9-]+');
        Route::post('/{post}/react',     [BlogController::class, 'react'])  ->name('react');

        // Authoring (any auth user)
        Route::post('/',                 [BlogController::class, 'store'])   ->name('store');
        Route::put('/{post}',            [BlogController::class, 'update'])  ->name('update');
        Route::delete('/{post}',         [BlogController::class, 'destroy']) ->name('destroy');
    });

    // ── Surveys — user-facing (any auth user) ─────────────────────────────────
    Route::prefix('surveys')->name('surveys.')->group(function (): void {
        Route::get('/',                    [SurveyController::class, 'index'])   ->name('index');
        Route::get('/{survey}',            [SurveyController::class, 'show'])    ->name('show');
        Route::post('/{survey}/respond',   [SurveyController::class, 'respond']) ->name('respond');
    });

    // ── Teams — leave (any auth user, declared before trainer group) ──────────
    Route::post('/teams/{team}/leave', [TeamController::class, 'leave'])
        ->name('teams.leave');

    // ── Teams — trainer management ────────────────────────────────────────────
    Route::middleware('role:trainer')->prefix('teams')->name('teams.')->group(function (): void {
        Route::get('/',    [TeamController::class, 'index'])  ->name('index');
        Route::post('/',   [TeamController::class, 'store'])  ->name('store');
        Route::get('/{team}',    [TeamController::class, 'show'])    ->name('show');
        Route::put('/{team}',    [TeamController::class, 'update'])  ->name('update');
        Route::delete('/{team}', [TeamController::class, 'destroy']) ->name('destroy');

        Route::post('/{team}/members',                  [TeamController::class, 'addMembers'])      ->name('members.add');
        Route::delete('/{team}/members/{user}',         [TeamController::class, 'removeMember'])    ->name('members.remove');
        Route::put('/{team}/members/priority',          [TeamController::class, 'reorderPriority']) ->name('members.priority');

        Route::get('/{team}/leave-requests',                                  [TeamController::class, 'leaveRequests'])  ->name('leave-requests.index');
        Route::patch('/{team}/leave-requests/{leaveRequest}/respond',         [TeamController::class, 'respondToLeave']) ->name('leave-requests.respond');
    });

    // ── Trainer surveys ───────────────────────────────────────────────────────
    Route::middleware('role:trainer')->prefix('trainer/surveys')->name('trainer.surveys.')->group(function (): void {
        Route::get('/',           [TrainerSurveyController::class, 'index'])   ->name('index');
        Route::post('/',          [TrainerSurveyController::class, 'store'])   ->name('store');
        Route::get('/{survey}',   [TrainerSurveyController::class, 'show'])    ->name('show');
        Route::delete('/{survey}',[TrainerSurveyController::class, 'destroy']) ->name('destroy');
        Route::get('/{survey}/results', [TrainerSurveyController::class, 'results']) ->name('results');

        Route::post('/{survey}/questions',                    [TrainerSurveyController::class, 'storeQuestion'])   ->name('questions.store');
        Route::put('/{survey}/questions/{question}',          [TrainerSurveyController::class, 'updateQuestion'])  ->name('questions.update');
        Route::delete('/{survey}/questions/{question}',       [TrainerSurveyController::class, 'destroyQuestion']) ->name('questions.destroy');
    });

    // ── Admin ─────────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {

        // User management
        Route::get('/users',                    [AdminUserController::class, 'index'])        ->name('users.index');
        Route::post('/users',                   [AdminUserController::class, 'store'])        ->name('users.store');
        Route::get('/users/{user}',             [AdminUserController::class, 'show'])         ->name('users.show');
        Route::put('/users/{user}',             [AdminUserController::class, 'update'])       ->name('users.update');
        Route::patch('/users/{user}/status',    [AdminUserController::class, 'updateStatus']) ->name('users.status');
        Route::delete('/users/{user}',          [AdminUserController::class, 'destroy'])      ->name('users.destroy');

        // Blog management
        Route::prefix('blog')->name('blog.')->group(function (): void {
            Route::get('/',                   [AdminBlogController::class, 'index'])   ->name('index');
            Route::patch('/{post}/approve',   [AdminBlogController::class, 'approve'])->name('approve');
            Route::patch('/{post}/reject',    [AdminBlogController::class, 'reject'])  ->name('reject');
            Route::delete('/{post}',          [AdminBlogController::class, 'destroy']) ->name('destroy');

            Route::prefix('categories')->name('categories.')->group(function (): void {
                Route::get('/',                  [AdminBlogController::class, 'listCategories'])   ->name('index');
                Route::post('/',                 [AdminBlogController::class, 'storeCategory'])    ->name('store');
                Route::put('/{category}',        [AdminBlogController::class, 'updateCategory'])   ->name('update');
                Route::delete('/{category}',     [AdminBlogController::class, 'destroyCategory'])  ->name('destroy');
            });

            Route::prefix('tags')->name('tags.')->group(function (): void {
                Route::get('/',              [AdminBlogController::class, 'listTags'])   ->name('index');
                Route::post('/',             [AdminBlogController::class, 'storeTag'])   ->name('store');
                Route::put('/{tag}',         [AdminBlogController::class, 'updateTag'])  ->name('update');
                Route::delete('/{tag}',      [AdminBlogController::class, 'destroyTag']) ->name('destroy');
            });
        });

        // Survey management
        Route::prefix('surveys')->name('surveys.')->group(function (): void {
            Route::get('/',           [AdminSurveyController::class, 'index'])   ->name('index');
            Route::post('/',          [AdminSurveyController::class, 'store'])   ->name('store');
            Route::get('/{survey}',   [AdminSurveyController::class, 'show'])    ->name('show');
            Route::delete('/{survey}',[AdminSurveyController::class, 'destroy']) ->name('destroy');
            Route::get('/{survey}/results', [AdminSurveyController::class, 'results']) ->name('results');

            Route::post('/{survey}/questions',                  [AdminSurveyController::class, 'storeQuestion'])   ->name('questions.store');
            Route::put('/{survey}/questions/{question}',        [AdminSurveyController::class, 'updateQuestion'])  ->name('questions.update');
            Route::delete('/{survey}/questions/{question}',     [AdminSurveyController::class, 'destroyQuestion']) ->name('questions.destroy');
        });
    });
});
