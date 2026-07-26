<?php

use App\Http\Controllers\Api\Admin\AdminAchievementController;
use App\Http\Controllers\Api\Admin\AdminBlogController;
use App\Http\Controllers\Api\Admin\AdminContactInfoController;
use App\Http\Controllers\Api\Admin\AdminDebateController;
use App\Http\Controllers\Api\Admin\AdminStatsController;
use App\Http\Controllers\Api\Admin\AdminSurveyController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\ActivityStatsController;
use App\Http\Controllers\Api\AttendanceStatsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoachTeamSummaryController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\DebateChatController;
use App\Http\Controllers\Api\DebateController;
use App\Http\Controllers\Api\DebaterStatsController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\LiveDebateController;
use App\Http\Controllers\Api\LiveKitController;
use App\Http\Controllers\Api\LiveKitWebhookController;
use App\Http\Controllers\Api\DebateFormatController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MotionController;
use App\Http\Controllers\Api\MotionFrameworkController;
use App\Http\Controllers\Api\NotificationController;
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

// ── LiveKit webhook (public — signature-verified, no Sanctum) ─────────────────
Route::post('/livekit/webhook', [LiveKitWebhookController::class, 'handle'])
    ->name('livekit.webhook');

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

    // ── Blog — reading, reactions & authoring (any auth user) ────────────────
    Route::prefix('blog')->name('blog.')->group(function (): void {
        Route::get('/',              [BlogController::class, 'index'])  ->name('index');
        // Literal paths MUST be registered before the /{slug} wildcard.
        // /blog/categories and /blog/tags are friendlier aliases of the
        // /admin/blog/* list routes below (which any auth user could already read).
        Route::get('/categories',    [AdminBlogController::class, 'listCategories'])->name('categories.index');
        Route::get('/tags',          [AdminBlogController::class, 'listTags'])      ->name('tags.index');
        Route::get('/authors',       [BlogController::class, 'authors'])            ->name('authors');
        Route::get('/{slug}',        [BlogController::class, 'show'])   ->name('show')
            ->where('slug', '[a-zA-Z0-9-]+');
        Route::post('/{post}/react', [BlogController::class, 'react'])  ->name('react');
        Route::post('/',             [BlogController::class, 'store'])   ->name('store');
        Route::put('/{post}',        [BlogController::class, 'update'])  ->name('update');
        Route::delete('/{post}',     [BlogController::class, 'destroy']) ->name('destroy');
    });

    // Blog taxonomy lists — readable by ANY auth user (used to populate the
    // category/tag pickers when authoring a post). Same URLs as before; the
    // create/update/delete for these stay admin-only (see the admin group).
    Route::get('/admin/blog/categories', [AdminBlogController::class, 'listCategories'])->name('admin.blog.categories.index');
    Route::get('/admin/blog/tags',       [AdminBlogController::class, 'listTags'])      ->name('admin.blog.tags.index');

    // ── Surveys — user-facing (any auth user) ─────────────────────────────────
    Route::prefix('surveys')->name('surveys.')->group(function (): void {
        Route::get('/',                  [SurveyController::class, 'index'])   ->name('index');
        Route::get('/{survey}',          [SurveyController::class, 'show'])    ->name('show');
        Route::post('/{survey}/respond', [SurveyController::class, 'respond']) ->name('respond');
    });

    // ── Search ────────────────────────────────────────────────────────────────
    Route::get('/search', [SearchController::class, 'index'])->name('search');

    // ── V2 §3 — leaderboards (public top-10 rankings) ─────────────────────────
    Route::get('/leaderboards/debaters', [LeaderboardController::class, 'debaters'])->name('leaderboards.debaters');
    Route::get('/leaderboards/teams',    [LeaderboardController::class, 'teams'])   ->name('leaderboards.teams');

    // ── Debate formats — read (any auth user) ────────────────────────────────
    Route::prefix('debate-formats')->name('debate-formats.')->group(function (): void {
        Route::get('/',         [DebateFormatController::class, 'index']) ->name('index');
        Route::get('/{format}', [DebateFormatController::class, 'show'])  ->name('show');
    });

    // ── Motion frameworks — read (any auth user) ──────────────────────────────
    Route::get('/motion-frameworks', [MotionFrameworkController::class, 'index'])->name('motion-frameworks.index');

    // ── Motions — read (any auth), write (judge/admin checked in controller) ──
    Route::prefix('motions')->name('motions.')->group(function (): void {
        Route::get('/',           [MotionController::class, 'index'])   ->name('index');
        Route::get('/{motion}',   [MotionController::class, 'show'])    ->name('show');
        Route::post('/',          [MotionController::class, 'store'])   ->name('store');
        Route::put('/{motion}',   [MotionController::class, 'update'])  ->name('update');
        Route::delete('/{motion}',[MotionController::class, 'destroy']) ->name('destroy');
    });

    // ── Debates — user-facing ─────────────────────────────────────────────────
    Route::prefix('debates')->name('debates.')->group(function (): void {
        Route::get('/',                       [DebateController::class, 'index'])              ->name('index');
        // Literal paths MUST be registered before the /{debate} wildcard.
        Route::get('/search',                 [DebateController::class, 'search'])             ->name('search');
        Route::get('/tags/distinct',          [DebateController::class, 'distinctTags'])       ->name('tags.distinct');
        Route::get('/{debate}',               [DebateController::class, 'show'])               ->name('show');
        Route::get('/{debate}/registerable-teams', [DebateController::class, 'registerableTeams'])->name('registerable-teams');
        Route::get('/{debate}/registrations', [DebateController::class, 'registrations'])        ->name('registrations');
        Route::post('/{debate}/register',     [DebateController::class, 'register'])           ->name('register');
        Route::post('/{debate}/team-roster',  [DebateController::class, 'teamRoster'])         ->name('team-roster');
        Route::get('/{debate}/token',         [LiveKitController::class, 'getToken'])          ->name('token');

        // Sprinkles §2 — persistent team chat (team resolved server-side from
        // the caller's own participant row; additive to the peer team_chat event).
        Route::get('/{debate}/chat',        [DebateChatController::class, 'index'])   ->name('chat.index');
        Route::post('/{debate}/chat',       [DebateChatController::class, 'store'])   ->name('chat.store');
        Route::post('/{debate}/chat/read',  [DebateChatController::class, 'markRead'])->name('chat.read');

        // Live session endpoints
        Route::get('/{debate}/live-state',              [LiveDebateController::class, 'state'])          ->name('live-state');
        Route::post('/{debate}/team-speakers',          [LiveDebateController::class, 'setTeamSpeakers'])->name('team-speakers');
        Route::post('/{debate}/start-live',             [LiveDebateController::class, 'startLive'])      ->name('start-live');
        Route::post('/{debate}/next-stage',             [LiveDebateController::class, 'nextStage'])      ->name('next-stage');
        Route::post('/{debate}/timer',                  [LiveDebateController::class, 'timer'])          ->name('timer');
        Route::post('/{debate}/rollback-to-lobby',      [LiveDebateController::class, 'rollbackToLobby'])->name('rollback-to-lobby');
        Route::post('/{debate}/stages/{stage}/poi',     [LiveDebateController::class, 'reportPoi'])      ->name('stages.poi');
        Route::post('/{debate}/result',                 [LiveDebateController::class, 'submitResult'])   ->name('result');
        Route::post('/{debate}/result/reveal',          [LiveDebateController::class, 'revealResult'])   ->name('result.reveal');
        Route::post('/{debate}/close-main',             [LiveDebateController::class, 'closeMain'])      ->name('close-main');
        Route::post('/{debate}/close-room',             [LiveDebateController::class, 'closeRoom'])      ->name('close-room');
    });

    // ── Sprinkles §6.1–§6.4: public user profiles ─────────────────────────────
    Route::prefix('users/{user}')->name('users.')->group(function (): void {
        Route::get('/',               [UserProfileController::class, 'show'])        ->name('show');
        Route::get('/achievements',   [UserProfileController::class, 'achievements'])->name('achievements');
        Route::get('/teams',          [UserProfileController::class, 'teams'])       ->name('teams');
        Route::get('/teams/history',  [UserProfileController::class, 'teamsHistory'])->name('teams.history');
    });

    // ── Sprinkles §8: option lists for the debate-search filter dialog ────────
    Route::get('/judges',        [UserProfileController::class, 'judges']) ->name('judges.index');
    Route::get('/teams/options', [TeamController::class, 'options'])       ->name('teams.options');

    // ── Debater statistics (debater=own, coach=supervised, admin=any) ─────────
    Route::prefix('debaters/{debater}/stats')->name('debaters.stats.')->group(function (): void {
        Route::get('/win-rate',      [DebaterStatsController::class, 'winRate'])     ->name('win-rate');
        Route::get('/avg-score',     [DebaterStatsController::class, 'avgScore'])    ->name('avg-score');
        Route::get('/best-speaker',  [DebaterStatsController::class, 'bestSpeaker']) ->name('best-speaker');
        Route::get('/score-ranking', [DebaterStatsController::class, 'scoreRanking'])->name('score-ranking');
        Route::get('/improvement',   [DebaterStatsController::class, 'improvement']) ->name('improvement');
        // Sprinkles §6.5 — prep-room attendance (same auth policy as the rest).
        Route::get('/prep-attendance', [AttendanceStatsController::class, 'debater'])->name('prep-attendance');
        // V2 §7 — activity/participation score (additional "kind" on the same screen).
        Route::get('/activity', [ActivityStatsController::class, 'debater'])->name('activity');
    });

    // ── Sprinkles §6.5 + V2 §7: coach + judge attendance/activity stats ────────
    Route::get('/trainers/{trainer}/stats/attendance', [AttendanceStatsController::class, 'trainer'])->name('trainers.stats.attendance');
    Route::get('/judges/{judge}/stats/attendance',     [AttendanceStatsController::class, 'judge'])  ->name('judges.stats.attendance');
    Route::get('/trainers/{trainer}/stats/activity',   [ActivityStatsController::class, 'trainer'])  ->name('trainers.stats.activity');
    Route::get('/judges/{judge}/stats/activity',       [ActivityStatsController::class, 'judge'])    ->name('judges.stats.activity');

    // V2 §3 — coach team-summary (avg improvement/win-rate/score/activity across the coach's teams).
    Route::get('/trainers/{trainer}/stats/team-summary', [CoachTeamSummaryController::class, 'show'])->name('trainers.stats.team-summary');

    // ── Feedback (any auth user) ──────────────────────────────────────────────
    Route::prefix('feedback')->name('feedback.')->group(function (): void {
        Route::get('/',  [FeedbackController::class, 'index']) ->name('index');
        Route::post('/', [FeedbackController::class, 'store']) ->name('store');
    });

    // ── Complaints — user actions ─────────────────────────────────────────────
    Route::prefix('complaints')->name('complaints.')->group(function (): void {
        Route::get('/mine', [ComplaintController::class, 'myComplaints']) ->name('mine');
        Route::post('/',    [ComplaintController::class, 'store'])         ->name('store');
    });

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::prefix('notifications')->name('notifications.')->group(function (): void {
        Route::get('/',                          [NotificationController::class, 'index'])       ->name('index');
        Route::patch('/read-all',                [NotificationController::class, 'markAllRead']) ->name('read-all');
        Route::patch('/{notification}/read',     [NotificationController::class, 'markRead'])    ->name('read');
        Route::delete('/{notification}',         [NotificationController::class, 'destroy'])     ->name('destroy');
    });

    // ── Teams — leave (any auth user, declared before trainer group) ──────────
    Route::post('/teams/{team}/leave', [TeamController::class, 'leave'])
        ->name('teams.leave');

    // ── Teams — trainer management ────────────────────────────────────────────
    Route::middleware('role:admin,trainer')->prefix('teams')->name('teams.')->group(function (): void {
        Route::get('/',          [TeamController::class, 'index'])->name('index');
        Route::post('/',         [TeamController::class, 'store'])->name('store');
        Route::get('/{team}',    [TeamController::class, 'show'])->name('show');
        Route::put('/{team}',    [TeamController::class, 'update'])->name('update');
        Route::delete('/{team}', [TeamController::class, 'destroy'])->name('destroy');

        Route::post('/{team}/members',              [TeamController::class, 'addMembers'])->name('members.add');
        Route::delete('/{team}/members/{user}',     [TeamController::class, 'removeMember'])->name('members.remove');
        Route::put('/{team}/members/priority',      [TeamController::class, 'reorderPriority'])->name('members.priority');

        Route::get('/{team}/leave-requests',                            [TeamController::class, 'leaveRequests'])->name('leave-requests.index');
        Route::patch('/{team}/leave-requests/{leaveRequest}/respond',   [TeamController::class, 'respondToLeave'])->name('leave-requests.respond');
    });

    // ── Trainer surveys ───────────────────────────────────────────────────────
    Route::middleware('role:trainer')->prefix('trainer/surveys')->name('trainer.surveys.')->group(function (): void {
        Route::get('/',            [TrainerSurveyController::class, 'index'])   ->name('index');
        Route::post('/',           [TrainerSurveyController::class, 'store'])   ->name('store');
        Route::get('/{survey}',    [TrainerSurveyController::class, 'show'])    ->name('show');
        Route::put('/{survey}',    [TrainerSurveyController::class, 'update'])  ->name('update');
        Route::delete('/{survey}', [TrainerSurveyController::class, 'destroy']) ->name('destroy');
        Route::get('/{survey}/results', [TrainerSurveyController::class, 'results']) ->name('results');

        Route::post('/{survey}/questions',              [TrainerSurveyController::class, 'storeQuestion'])   ->name('questions.store');
        Route::put('/{survey}/questions/{question}',    [TrainerSurveyController::class, 'updateQuestion'])  ->name('questions.update');
        Route::delete('/{survey}/questions/{question}', [TrainerSurveyController::class, 'destroyQuestion']) ->name('questions.destroy');
    });

    // ── Evaluations — trainer only ────────────────────────────────────────────
    Route::middleware('role:trainer')->prefix('evaluations')->name('evaluations.')->group(function (): void {
        Route::get('/',                  [EvaluationController::class, 'index']) ->name('index');
        Route::post('/',                 [EvaluationController::class, 'store']) ->name('store');
        Route::get('/{evaluation}',      [EvaluationController::class, 'show'])  ->name('show');
    });

    // ── Admin ─────────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function (): void {

        // User management
        Route::get('/users',                 [AdminUserController::class, 'index'])        ->name('users.index');
        Route::post('/users',                [AdminUserController::class, 'store'])        ->name('users.store');
        Route::get('/users/{user}',          [AdminUserController::class, 'show'])         ->name('users.show');
        Route::put('/users/{user}',          [AdminUserController::class, 'update'])       ->name('users.update');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus']) ->name('users.status');
        Route::delete('/users/{user}',       [AdminUserController::class, 'destroy'])      ->name('users.destroy');

        // Achievement catalog — pre-defined achievements (name/type/image),
        // shared across users. Not paginated: a small, curated reference list.
        Route::prefix('achievements')->name('achievements.')->group(function (): void {
            Route::get('/',                 [AdminAchievementController::class, 'index'])  ->name('index');
            Route::post('/',                [AdminAchievementController::class, 'store'])  ->name('store');
            Route::get('/{achievement}',    [AdminAchievementController::class, 'show'])   ->name('show');
            Route::put('/{achievement}',    [AdminAchievementController::class, 'update']) ->name('update');
            Route::delete('/{achievement}', [AdminAchievementController::class, 'destroy'])->name('destroy');
        });

        // Achievement awarding — assign/revoke a catalog achievement to/from a user.
        Route::get('/users/{user}/achievements/available',        [AdminAchievementController::class, 'available'])->name('users.achievements.available');
        Route::post('/users/{user}/achievements',                  [AdminAchievementController::class, 'assign'])  ->name('users.achievements.store');
        Route::delete('/users/{user}/achievements/{achievement}',  [AdminAchievementController::class, 'revoke'])  ->name('users.achievements.destroy');

        // Blog management
        Route::prefix('blog')->name('blog.')->group(function (): void {
            Route::get('/',                 [AdminBlogController::class, 'index'])   ->name('index');
            Route::patch('/{post}/approve', [AdminBlogController::class, 'approve'])->name('approve');
            Route::patch('/{post}/reject',  [AdminBlogController::class, 'reject'])  ->name('reject');
            Route::delete('/{post}',        [AdminBlogController::class, 'destroy']) ->name('destroy');

            // NOTE: the GET (list) routes for categories & tags are registered in
            // the authenticated group above (readable by ANY auth user — needed to
            // author posts). Only the writes below stay admin-only.
            Route::prefix('categories')->name('categories.')->group(function (): void {
                Route::post('/',             [AdminBlogController::class, 'storeCategory'])   ->name('store');
                Route::put('/{category}',    [AdminBlogController::class, 'updateCategory'])  ->name('update');
                Route::delete('/{category}', [AdminBlogController::class, 'destroyCategory']) ->name('destroy');
            });

            Route::prefix('tags')->name('tags.')->group(function (): void {
                Route::post('/',         [AdminBlogController::class, 'storeTag'])   ->name('store');
                Route::put('/{tag}',     [AdminBlogController::class, 'updateTag'])  ->name('update');
                Route::delete('/{tag}',  [AdminBlogController::class, 'destroyTag']) ->name('destroy');
            });
        });

        // Survey management
        Route::prefix('surveys')->name('surveys.')->group(function (): void {
            Route::get('/',            [AdminSurveyController::class, 'index'])   ->name('index');
            Route::post('/',           [AdminSurveyController::class, 'store'])   ->name('store');
            Route::get('/{survey}',    [AdminSurveyController::class, 'show'])    ->name('show');
            Route::put('/{survey}',    [AdminSurveyController::class, 'update'])  ->name('update');
            Route::delete('/{survey}', [AdminSurveyController::class, 'destroy']) ->name('destroy');
            Route::get('/{survey}/results', [AdminSurveyController::class, 'results']) ->name('results');

            Route::post('/{survey}/questions',              [AdminSurveyController::class, 'storeQuestion'])   ->name('questions.store');
            Route::put('/{survey}/questions/{question}',    [AdminSurveyController::class, 'updateQuestion'])  ->name('questions.update');
            Route::delete('/{survey}/questions/{question}', [AdminSurveyController::class, 'destroyQuestion']) ->name('questions.destroy');
        });

        // Debate format management
        Route::prefix('debate-formats')->name('debate-formats.')->group(function (): void {
            Route::post('/',          [DebateFormatController::class, 'store'])   ->name('store');
            Route::put('/{format}',   [DebateFormatController::class, 'update'])  ->name('update');
            Route::delete('/{format}',[DebateFormatController::class, 'destroy']) ->name('destroy');
        });

        // Motion framework management
        Route::prefix('motion-frameworks')->name('motion-frameworks.')->group(function (): void {
            Route::post('/',              [MotionFrameworkController::class, 'store'])   ->name('store');
            Route::put('/{framework}',    [MotionFrameworkController::class, 'update'])  ->name('update');
            Route::delete('/{framework}', [MotionFrameworkController::class, 'destroy']) ->name('destroy');
        });

        // Debate management
        Route::prefix('debates')->name('debates.')->group(function (): void {
            Route::get('/',    [AdminDebateController::class, 'index'])   ->name('index');
            Route::post('/',   [AdminDebateController::class, 'store'])   ->name('store');
            Route::get('/{debate}',    [AdminDebateController::class, 'show'])    ->name('show');
            Route::put('/{debate}',    [AdminDebateController::class, 'update'])  ->name('update');
            Route::delete('/{debate}', [AdminDebateController::class, 'destroy']) ->name('destroy');
            Route::patch('/{debate}/start', [AdminDebateController::class, 'start']) ->name('start');

            Route::post('/{debate}/participants',
                [AdminDebateController::class, 'assignParticipants'])
                ->name('participants.assign');

            Route::post('/{debate}/teams',
                [AdminDebateController::class, 'linkTeams'])
                ->name('teams.link');

            Route::post('/{debate}/announce',
                [AdminDebateController::class, 'announce'])
                ->name('announce');

            Route::get('/{debate}/teams/{team}/pending-participants',
                [AdminDebateController::class, 'pendingParticipants'])
                ->name('teams.pending-participants');

            Route::patch('/{debate}/participants/{participant}/status',
                [AdminDebateController::class, 'updateParticipantStatus'])
                ->name('participants.status');

            Route::post('/{debate}/judges/order',
                [AdminDebateController::class, 'setJudgesOrder'])
                ->name('judges.order');
        });

        // Complaint management
        Route::prefix('complaints')->name('complaints.')->group(function (): void {
            Route::get('/',                [ComplaintController::class, 'index'])  ->name('index');
            Route::get('/{complaint}',     [ComplaintController::class, 'show'])   ->name('show');
            Route::patch('/{complaint}',   [ComplaintController::class, 'update']) ->name('update');
        });

        // V2 — admin-editable support contact (email/phone/instagram, served on login)
        Route::prefix('contact-info')->name('contact-info.')->group(function (): void {
            Route::get('/',  [AdminContactInfoController::class, 'show'])  ->name('show');
            Route::put('/',  [AdminContactInfoController::class, 'update'])->name('update');
        });

        // Platform-wide statistics (JSON + Excel export per stat)
        Route::prefix('stats')->name('stats.')->group(function (): void {
            Route::get('/framework-fairness',        [AdminStatsController::class, 'frameworkFairness'])       ->name('framework-fairness');
            Route::get('/framework-fairness/export', [AdminStatsController::class, 'frameworkFairnessExport']) ->name('framework-fairness.export');

            Route::get('/leaderboard',               [AdminStatsController::class, 'leaderboard'])             ->name('leaderboard');
            Route::get('/leaderboard/export',        [AdminStatsController::class, 'leaderboardExport'])       ->name('leaderboard.export');

            Route::get('/platform-health',           [AdminStatsController::class, 'platformHealth'])          ->name('platform-health');
            Route::get('/platform-health/export',    [AdminStatsController::class, 'platformHealthExport'])    ->name('platform-health.export');

            Route::get('/engagement-churn',          [AdminStatsController::class, 'engagementChurn'])         ->name('engagement-churn');
            Route::get('/engagement-churn/export',   [AdminStatsController::class, 'engagementChurnExport'])   ->name('engagement-churn.export');

            Route::get('/complaint-accountability',        [AdminStatsController::class, 'complaintAccountability'])       ->name('complaint-accountability');
            Route::get('/complaint-accountability/export', [AdminStatsController::class, 'complaintAccountabilityExport']) ->name('complaint-accountability.export');
        });
    });
});
