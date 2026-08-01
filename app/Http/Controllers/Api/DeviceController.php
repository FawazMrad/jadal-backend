<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Http\Requests\Device\UnregisterDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * FCM device registration (frontend spec §7.2.1).
 *
 * Both endpoints are idempotent, because the app calls them on every login,
 * every token rotation, and every app-language switch.
 */
class DeviceController extends Controller
{
    /**
     * POST /devices — upsert keyed on the TOKEN.
     *
     * Keying on the token (not on user+token) is what makes a device handed to
     * a different user re-assign rather than duplicate: without it, user A's
     * pushes would keep arriving on a phone now used by user B.
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        Device::updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'user_id'  => $request->user()->id,
                'platform' => $request->validated('platform'),
                'locale'   => $request->validated('locale', 'ar'),
            ]
        );

        return $this->success(null, 'تم تسجيل الجهاز. | Device registered.');
    }

    /**
     * DELETE /devices — by token, since the app only knows its own token.
     * Deleting an unknown token is a 200: the caller's desired end state
     * ("this token is not registered") already holds.
     */
    public function destroy(UnregisterDeviceRequest $request): JsonResponse
    {
        Device::where('token', $request->validated('token'))->delete();

        return $this->success(null, 'تم إلغاء تسجيل الجهاز. | Device unregistered.');
    }
}
