<?php

namespace App\Http\Controllers;

use App\Notifications\FollowUpAssignedNotification;
use App\Notifications\ScreeningDesignChangedNotification;
use App\Notifications\ScreeningDesignRevertedNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own unread notices (e.g. "a follow-up was assigned to you").
 * Always scoped to the authenticated user's notifications, so no further
 * policy is needed; payloads hold ids only (see FollowUpAssignedNotification).
 */
class NotificationController extends Controller
{
    /**
     * The most notices `index` returns. The count is not capped, so the client
     * can show "50+" when there are more than the list holds.
     */
    public const LIST_LIMIT = 50;

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->unreadFollowUpNotices($request)
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $notification->data,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $notifications]);
    }

    /**
     * How many unread notices the caller has — the same set `index` lists (only
     * uncapped), so the sidebar count next to "Mis seguimientos" matches the
     * notices shown there and drops as they are read. A cheap COUNT over the
     * caller's own notifications only (the relation is keyed by the
     * authenticated user, so another user's or another school's notices can
     * never be counted).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $this->unreadFollowUpNotices($request)->count()],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['id' => $notification]]);
    }

    /**
     * The notification classes the "Mis seguimientos" section of "Alertas y
     * seguimientos" knows how to render: follow-up assignments and the
     * screening design changed/reverted notices (screen 08).
     */
    private const RENDERED_TYPES = [
        FollowUpAssignedNotification::class,
        ScreeningDesignChangedNotification::class,
        ScreeningDesignRevertedNotification::class,
    ];

    /**
     * The caller's unread notices of a kind the page renders. Both `index` and
     * `unreadCount` read from here so the list and the sidebar count share one
     * definition — a future database notification type will not inflate the
     * count with notices the page cannot show.
     */
    private function unreadFollowUpNotices(Request $request): MorphMany
    {
        return $request->user()->unreadNotifications()
            ->whereIn('type', self::RENDERED_TYPES);
    }
}
