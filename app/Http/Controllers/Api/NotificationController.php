<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->paginate(20);

        return $this->paginated($notifications, NotificationResource::class, 'تم جلب الإشعارات. | Notifications retrieved.');
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        if (is_null($notification->read_at)) {
            $notification->update(['read_at' => now()]);
        }

        return $this->success(
            new NotificationResource($notification),
            'تم تحديد الإشعار كمقروء. | Notification marked as read.'
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success(null, 'تم تحديد كل الإشعارات كمقروءة. | All notifications marked as read.');
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return $this->error('غير مصرح. | Forbidden.', [], 403);
        }

        $notification->delete();

        return $this->success(null, 'تم حذف الإشعار. | Notification deleted.');
    }
}
