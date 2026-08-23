<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * OPTIONAL authentication for the two guest-reachable
 * endpoints (live-state, token). Everything else keeps hard `auth:sanctum`.
 *
 * Three cases, deliberately distinguished:
 *
 *   no Authorization header  → guest. $request->user() stays null and the
 *                              controller renders the locked-down projection.
 *   valid bearer token       → fully authenticated. Auth::shouldUse('sanctum')
 *                              makes $request->user() resolve exactly as it
 *                              does under `auth:sanctum`, so every existing
 *                              role path behaves identically.
 *   INVALID bearer token     → 401. NOT silently downgraded to guest: a user
 *                              whose token expired must be told to re-auth,
 *                              not quietly handed a stripped payload that
 *                              looks like a permissions bug on the client.
 *
 * Why the guard has to be named explicitly: the app's default guard is `web`
 * (session-based — see config/auth.php). Simply dropping `auth:sanctum` from
 * the route would make $request->user() return null for EVERY caller, bearer
 * token or not, silently downgrading real users to guests.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request); // genuine tokenless guest
        }

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى. | Session expired. Please sign in again.',
                'errors'  => [],
            ], 401);
        }

        // Promote the sanctum guard to default for this request so
        // $request->user() resolves downstream (controllers, resources,
        // check.status) exactly as under the `auth:sanctum` middleware.
        Auth::shouldUse('sanctum');

        return $next($request);
    }
}
