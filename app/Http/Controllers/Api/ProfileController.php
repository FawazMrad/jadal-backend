<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UploadAvatarRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    // ── FR-3: View own profile ────────────────────────────────────────────────

    public function show(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()),
            'تم جلب الملف الشخصي بنجاح. | Profile retrieved.'
        );
    }

    // ── FR-3: Edit own profile ────────────────────────────────────────────────

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->validated());

        return $this->success(
            new UserResource($request->user()->fresh()),
            'تم تحديث الملف الشخصي بنجاح. | Profile updated.'
        );
    }

    // ── Avatar upload ─────────────────────────────────────────────────────────

    public function uploadAvatar(UploadAvatarRequest $request): JsonResponse
    {
        $file      = $request->file('avatar');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;

        // Store under storage/app/public/avatars/{uuid}.ext
        Storage::disk('public')->putFileAs('avatars', $file, $filename);

        $avatarPath = 'avatars/' . $filename;

        // Delete old avatar file if it exists and is a storage path (not an external URL)
        $user = $request->user();
        if ($user->avatar_url && ! str_starts_with($user->avatar_url, 'http')) {
            Storage::disk('public')->delete($user->avatar_url);
        }

        $user->update(['avatar_url' => $avatarPath]);

        return $this->success(
            ['avatar_url' => Storage::disk('public')->url($avatarPath)],
            'تم رفع الصورة الشخصية بنجاح. | Avatar uploaded successfully.'
        );
    }

    // ── Password change ───────────────────────────────────────────────────────

    public function changePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error(
                'كلمة المرور الحالية غير صحيحة. | Current password is incorrect.',
                ['current_password' => 'Wrong password.'],
                422
            );
        }

        $user->update(['password' => $request->password]);

        // Revoke all OTHER tokens except the current one so session stays active
        $currentTokenId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return $this->success(null, 'تم تغيير كلمة المرور بنجاح. | Password changed successfully.');
    }
}
