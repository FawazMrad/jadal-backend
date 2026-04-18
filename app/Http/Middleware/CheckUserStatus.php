<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            // Revoke the current token so suspended/banned users cannot continue using the API
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'حسابك موقوف أو محظور. | Your account is suspended or banned.',
                'errors'  => [],
            ], 403);
        }

        return $next($request);
    }
}
