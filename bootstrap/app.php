<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',         // ← registers all /api/* routes
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         |------------------------------------------------------------------
         | Custom middleware aliases
         |------------------------------------------------------------------
         |
         | check.status — Rejects suspended/banned users on every protected
         |                route and revokes their current Sanctum token.
         |
         | role          — Guards a route by the user's `role` column.
         |                 Usage: role:admin  |  role:trainer  |  role:trainer,admin
         |
         | auth.optional — Resolves a Sanctum user WHEN a bearer token is
         |                 present, but lets a tokenless request through as a
         |                 guest instead of 401ing. Used ONLY by the two
         |                 guest-reachable debate endpoints; an invalid token
         |                 is still a 401.
         |
         */
        $middleware->alias([
            'check.status'  => \App\Http\Middleware\CheckUserStatus::class,
            'role'          => \App\Http\Middleware\RoleMiddleware::class,
            'auth.optional' => \App\Http\Middleware\OptionalSanctumAuth::class,
        ]);

        /*
         |------------------------------------------------------------------
         | API middleware group additions
         |------------------------------------------------------------------
         |
         | Sanctum's EnsureFrontendRequestsAreStateful is intentionally
         | omitted — this is a pure token-based API (no cookie/SPA auth).
         |
         */
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         |------------------------------------------------------------------
         | JSON error responses for API consumers
         |------------------------------------------------------------------
         |
         | When any unhandled exception reaches the handler on an /api/*
         | request, return the standard envelope format instead of HTML.
         |
         */
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'errors'  => $e->errors(),
                    ], 422);
                }

                if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Forbidden.',
                        'errors'  => [],
                    ], 403);
                }

                $status = method_exists($e, 'getStatusCode')
                    ? $e->getStatusCode()
                    : 500;

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Server error.',
                    'errors'  => [],
                ], $status);
            }
        });
    })->create();
