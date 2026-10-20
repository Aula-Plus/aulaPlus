<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Notifications\CommentSharedNotification;
use App\Notifications\FollowUpAssignedNotification;
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
        $notifications = $this->unreadNotices($request)
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $this->withStudentName($request, $notification->data),
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $notifications]);
    }

    /**
     * How many unread notices the caller has — the same set `index` lists (only
     * uncapped), so the sidebar count next to "Alertas y seguimientos" matches the
     * notices shown there and drops as they are read. A cheap COUNT over the
     * caller's own notifications only (the relation is keyed by the
     * authenticated user, so another user's or another school's notices can
     * never be counted).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $this->unreadNotices($request)->count()],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['id' => $notification]]);
    }

    /**
     * A shared-extract notice names the student (ClickUp 86e3qkwnv), but the
     * stored payload holds ids only; the name is resolved here, and only when
     * the caller may still see that student (tenant scope + StudentPolicy).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withStudentName(Request $request, array $data): array
    {
        if (($data['type'] ?? null) !== 'comment_shared') {
            return $data;
        }

        $student = Student::query()->find($data['student_id'] ?? null);

        return [
            ...$data,
            'student_name' => $student !== null && $request->user()->can('view', $student) ? $student->full_name : null,
        ];
    }

    /**
     * The caller's unread notices of the kinds "Alertas y seguimientos"
     * renders: follow-up assignments and shared extracts. Both `index` and
     * `unreadCount` read from here so the list and the sidebar count share
     * one definition — a future database notification type will not inflate
     * the count with notices the page cannot show.
     */
    private function unreadNotices(Request $request): MorphMany
    {
        return $request->user()->unreadNotifications()
            ->whereIn('type', [FollowUpAssignedNotification::class, CommentSharedNotification::class]);
    }
}
