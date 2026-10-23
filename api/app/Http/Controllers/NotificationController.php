<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\AlertOverdueNotification;
use App\Notifications\CommentSharedNotification;
use App\Notifications\FollowUpAssignedNotification;
use App\Notifications\ScreeningDesignChangedNotification;
use App\Notifications\ScreeningDesignRevertedNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The caller's own unread notices ("a follow-up was assigned to you", "an
 * alert you have reached its deadline without a way out").
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
        $notices = $this->unreadListedNotices($request)
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get();
        $studentNames = $this->sharedStudentNames($request, $notices->pluck('data'));

        $notifications = $notices
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $this->withStudentName($notification->data, $studentNames),
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
            'data' => ['count' => $this->unreadListedNotices($request)->count()],
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->unreadNotifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(['data' => ['id' => $notification]]);
    }

    /**
     * A shared-extract notice names the student (ClickUp 86e3qkwnv), but the
     * stored payload holds ids only; the names are resolved here in one query,
     * and only for students the caller may still see (tenant scope +
     * StudentPolicy).
     *
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @return array<int, string> student id => name
     */
    private function sharedStudentNames(Request $request, Collection $payloads): array
    {
        $ids = $payloads
            ->filter(fn (array $data) => ($data['type'] ?? null) === 'comment_shared')
            ->pluck('student_id')
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Student::query()
            ->whereKey($ids->all())
            ->get()
            ->filter(fn (Student $student) => $request->user()->can('view', $student))
            ->mapWithKeys(fn (Student $student) => [$student->id => $student->full_name])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $studentNames
     * @return array<string, mixed>
     */
    private function withStudentName(array $data, array $studentNames): array
    {
        if (($data['type'] ?? null) !== 'comment_shared') {
            return $data;
        }

        return [...$data, 'student_name' => $studentNames[$data['student_id'] ?? 0] ?? null];
    }

    /**
     * The notice types the page renders: a follow-up assigned to you, an
     * alert you have that reached its deadline without a way out («Llegó a
     * su fecha sin salida», ClickUp 86e3jpzdp review of 14/10), an
     * accommodation proposal returned to you for corrections (86e3qkwkk), an
     * extract psychopedagogy shared with you (86e3qkwnv), and the screening
     * design changed/reverted notices (screen 08).
     */
    public const LISTED_TYPES = [
        FollowUpAssignedNotification::class,
        AlertOverdueNotification::class,
        AccommodationProposalReturnedNotification::class,
        CommentSharedNotification::class,
        ScreeningDesignChangedNotification::class,
        ScreeningDesignRevertedNotification::class,
    ];

    /**
     * The caller's unread notices of the kinds the page renders. Both `index`
     * and `unreadCount` read from here so the list and the sidebar count share
     * one definition — a future database notification type will not inflate
     * the count with notices the page cannot show.
     */
    private function unreadListedNotices(Request $request): MorphMany
    {
        return $request->user()->unreadNotifications()
            ->whereIn('type', self::LISTED_TYPES);
    }
}
