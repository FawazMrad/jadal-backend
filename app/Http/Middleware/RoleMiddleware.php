<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes:  ->middleware('role:admin')
     *                   ->middleware('role:trainer,admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول. | You are not authorized to access this resource.',
                'errors'  => [],
            ], 403);
        }

        return $next($request);
    }
}
