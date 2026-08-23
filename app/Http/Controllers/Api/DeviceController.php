<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Http\Requests\Device\UnregisterDeviceRequest;
use App\Models\Device;
use Illuminate\Http\JsonResponse;

/**
 * FCM device registration.
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
     *
     * Scoped to the authenticated user: matching on the token ALONE let any
     * authenticated caller unregister someone else's device if they knew its
     * token. Low severity (no data disclosure — the victim just stops
     * receiving pushes) but there is no legitimate flow that needs it: after a
     * device is reassigned to another user, the previous owner *should* fail
     * to delete it.
     *
     * Still idempotent — deleting an unknown token, or one belonging to
     * somebody else, is a 200. The caller's desired end state ("my token is
     * not registered") holds either way, and returning 404/403 would leak
     * whether an arbitrary token exists.
     */
    public function destroy(UnregisterDeviceRequest $request): JsonResponse
    {
        Device::where('token', $request->validated('token'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->success(null, 'تم إلغاء تسجيل الجهاز. | Device unregistered.');
    }
}
