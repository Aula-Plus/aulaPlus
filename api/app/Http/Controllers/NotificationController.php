<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Student;
use App\Notifications\AccommodationConfirmedNotification;
use App\Notifications\AccommodationProposalReturnedNotification;
use App\Notifications\AlertOverdueNotification;
use App\Notifications\CommentSharedNotification;
use App\Notifications\FollowUpAssignedNotification;
use App\Notifications\MaterialPublishedNotification;
use App\Notifications\MaterialRemovedNotification;
use App\Notifications\MeetingActionAssignedNotification;
use App\Notifications\MeetingSheetFragmentNotification;
use App\Notifications\ScreeningDesignChangedNotification;
use App\Notifications\ScreeningDesignRevertedNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The caller's own notices ("a follow-up was assigned to you", "an alert you
 * have reached its deadline without a way out", "a new accommodation is in
 * force for a student of yours"…).
 * Always scoped to the authenticated user's notifications, so no further
 * policy is needed; payloads hold ids only (see FollowUpAssignedNotification).
 *
 * `index` lists the unread ones by default (Inicio, «Alertas y seguimientos»);
 * `?filter=all` returns the history, read ones included with their `read_at`
 * (screen 22 «Notificaciones», filter «Todas / Sin leer»). Reading a notice
 * resolves nothing (P22-DEC-03): `markRead` / `markAllRead` only set read_at.
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
        $validated = $request->validate([
            'filter' => ['sometimes', 'string', 'in:unread,all'],
        ]);
        $query = ($validated['filter'] ?? 'unread') === 'all'
            ? $this->listedNotices($request)
            : $this->unreadListedNotices($request);

        $notices = $query
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get();
        $studentNames = $this->sharedStudentNames($request, $notices->pluck('data'));
        $materialTitles = $this->removedMaterialTitles($request, $notices->pluck('data'));

        $notifications = $notices
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'data' => $this->withMaterialTitle($this->withStudentName($notification->data, $studentNames), $materialTitles),
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $notifications]);
    }

    /**
     * How many unread notices the caller has — the same set `index` lists (only
     * uncapped), so the sidebar count next to "Notificaciones" matches the
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
     * «Marcar todas como leídas» (P22-BAN-02): only the caller's own unread
     * notices of the listed kinds, in one UPDATE.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->unreadListedNotices($request)->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }

    /**
     * Notices that name the student: a shared extract (ClickUp 86e3qkwnv) and
     * a new accommodation in force (screen 22) and a meeting sheet's fragment
     * that landed in the file (screen 10).
     */
    private const NAMES_STUDENT = ['comment_shared', 'accommodation_confirmed', 'meeting_sheet_fragment'];

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
            ->filter(fn (array $data) => in_array($data['type'] ?? null, self::NAMES_STUDENT, true))
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
     * «Sacaron del catálogo tu material X» names the material, but the stored
     * payload holds its id; the titles are resolved here in one query, only
     * for materials the caller may still see (tenant scope + MaterialPolicy).
     *
     * @param  Collection<int, array<string, mixed>>  $payloads
     * @return array<int, string> material id => title
     */
    private function removedMaterialTitles(Request $request, Collection $payloads): array
    {
        $ids = $payloads
            ->filter(fn (array $data) => ($data['type'] ?? null) === 'material_removed')
            ->pluck('material_id')
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        return Material::query()
            ->whereKey($ids->all())
            ->get(['id', 'school_id', 'owner_id', 'catalog_status', 'title'])
            ->filter(fn (Material $material) => $request->user()->can('view', $material))
            ->mapWithKeys(fn (Material $material) => [$material->id => $material->title])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $titles
     * @return array<string, mixed>
     */
    private function withMaterialTitle(array $data, array $titles): array
    {
        if (($data['type'] ?? null) !== 'material_removed') {
            return $data;
        }

        return [...$data, 'material_title' => $titles[$data['material_id'] ?? 0] ?? null];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $studentNames
     * @return array<string, mixed>
     */
    private function withStudentName(array $data, array $studentNames): array
    {
        if (! in_array($data['type'] ?? null, self::NAMES_STUDENT, true)) {
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
     * design changed/reverted notices (screen 08), and from screen 10 a
     * meeting sheet's fragment that landed in a file you read and an action
     * of a meeting left to you.
     */
    public const LISTED_TYPES = [
        FollowUpAssignedNotification::class,
        AlertOverdueNotification::class,
        AccommodationProposalReturnedNotification::class,
        AccommodationConfirmedNotification::class,
        CommentSharedNotification::class,
        ScreeningDesignChangedNotification::class,
        ScreeningDesignRevertedNotification::class,
        MeetingSheetFragmentNotification::class,
        MeetingActionAssignedNotification::class,
        // Screen 19: a material was added to the school's catalog, and the
        // owner's material was taken out of it.
        MaterialPublishedNotification::class,
        MaterialRemovedNotification::class,
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

    /**
     * The caller's notices of the listed kinds, read or not: the history of
     * screen 22.
     */
    private function listedNotices(Request $request): MorphMany
    {
        return $request->user()->notifications()
            ->whereIn('type', self::LISTED_TYPES);
    }
}
