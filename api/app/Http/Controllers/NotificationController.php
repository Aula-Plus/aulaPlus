<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own unread notices (e.g. "a follow-up was assigned to you").
 * Always scoped to the authenticated user's notifications, so no further
 * policy is needed; payloads hold ids only (see FollowUpAssignedNotification).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->unreadNotifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $notification->data,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $notifications]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['id' => $notification]]);
    }
}
