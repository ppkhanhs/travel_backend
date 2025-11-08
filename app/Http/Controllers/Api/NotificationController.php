<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json(['unread' => $count]);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $notificationId)
            ->firstOrFail();

        if (!$notification->read_at) {
            $notification->markAsRead();
        }

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $request->user()->update(['notifications_enabled' => $data['enabled']]);

        return response()->json([
            'message' => 'Notification preference updated.',
            'enabled' => $data['enabled'],
        ]);
    }
}

