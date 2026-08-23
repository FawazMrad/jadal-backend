<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\ContactInfo;
use App\Models\User;
use App\Services\GoogleTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthController extends Controller
{
    // ── FR-1: Email + Password Login ─────────────────────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->error(
                'بيانات الدخول غير صحيحة. | Invalid credentials.',
                [],
                401
            );
        }

        if ($user->status !== 'active') {
            return $this->statusForbidden($user->status);
        }

        $token = $user->createToken($request->input('device', 'api'))->plainTextToken;

        return $this->success([
            'token'   => $token,
            'user'    => new UserResource($user),
            'contact' => $this->supportContact(),
        ], 'تم تسجيل الدخول بنجاح. | Login successful.');
    }

    // ── Google Sign-In ────────────────────────────────────────────────────────

    public function googleLogin(GoogleLoginRequest $request, GoogleTokenService $googleService): JsonResponse
    {
        try {
            $email = $googleService->getEmailFromToken($request->id_token);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), [], 401);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            return $this->error(
                'Account not found. Contact administrator. | الحساب غير موجود. تواصل مع المسؤول.',
                [],
                403
            );
        }

        if ($user->status !== 'active') {
            return $this->statusForbidden($user->status);
        }

        $token = $user->createToken($request->input('device', 'google'))->plainTextToken;

        return $this->success([
            'token'   => $token,
            'user'    => new UserResource($user),
            'contact' => $this->supportContact(),
        ], 'تم تسجيل الدخول عبر Google بنجاح. | Google login successful.');
    }

    /**
     * Support contact for the app's nav drawer.
     * DB-backed singleton row (ContactInfoSeeder), so it's editable data rather
     * than a deploy-time env value; riding on login avoids a second round-trip.
     */
    private function supportContact(): array
    {
        return ContactInfo::payload(ContactInfo::current());
    }

    // ── FR-2: Logout ──────────────────────────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'تم تسجيل الخروج بنجاح. | Logged out successfully.');
    }

    // ── FR-4: Forgot Password ─────────────────────────────────────────────────

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Always return success to prevent email enumeration
            return response()->json(['success' => true, 'message' => 'If this email exists, a reset link has been sent.']);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('password_reset:' . $user->email, $code, now()->addMinutes(60));
        $user->sendPasswordResetNotification($code);

        return response()->json([
            'success' => true,
            'message' => 'If this email exists, a reset code has been sent.',
        ]);
    }
    // ── Reset Password ────────────────────────────────────────────────────────

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $cacheKey = 'password_reset:' . $request->email;
        $cached   = Cache::get($cacheKey);

        if (! $cached || $cached !== $request->token) {
            return $this->error(
                'الرمز غير صالح أو منتهي الصلاحية. | Invalid or expired reset code.',
                [],
                422
            );
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->error(
                'الرمز غير صالح أو منتهي الصلاحية. | Invalid or expired reset code.',
                [],
                422
            );
        }

        $user->forceFill(['password' => $request->password])->save();
        $user->tokens()->delete();
        Cache::forget($cacheKey);

        return $this->success(
            null,
            'تم إعادة تعيين كلمة المرور بنجاح. | Password has been reset successfully.'
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function statusForbidden(string $status): JsonResponse
    {
        $messages = [
            'suspended' => 'تم تعليق حسابك. تواصل مع الإدارة. | Your account has been suspended.',
            'banned'    => 'تم حظر حسابك نهائياً. | Your account has been permanently banned.',
        ];

        return $this->error(
            $messages[$status] ?? $messages['suspended'],
            ['status' => $status],
            403
        );
    }
}
