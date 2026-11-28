<?php

namespace App\Http\Controllers;

use App\Enums\CalendarEventType;
use App\Http\Requests\PersonalEventRequest;
use App\Http\Resources\StaffMemberResource;
use App\Models\CalendarEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Calendar\EventParticipants;
use App\Services\Calendar\SharedEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Events of each person's own calendar (screen 21, ClickUp 1dfd3mc56kp,
 * P21-DEC-108): every role creates them, invites any active user of the
 * school and may name one student. The event is stored once and appears in
 * the calendar of each participant, who is notified (no acceptance). Only
 * the creator edits or deletes it; each invitee may remove it from their own
 * calendar. Read through the Calendar feed (`GET /calendar`, items of type
 * `meeting` with `shared`).
 */
class PersonalEventController extends Controller
{
    public function __construct(
        protected EventParticipants $participants,
        protected SharedEvents $sharedEvents,
    ) {}

    /**
     * Who can be invited (active colleagues of the school, the caller
     * excluded) and which students can be named (the ones the caller sees).
     * Names and roles only.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('createPersonal', CalendarEvent::class);
        $user = $request->user();

        $colleagues = User::query()
            ->where('school_id', $user->school_id)
            ->whereKeyNot($user->id)
            ->whereNull('disabled_at')
            ->whereNotNull('password')
            ->with('roles')
            ->orderBy('name')
            ->get();

        $students = Student::query()
            ->visibleTo($user)
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Student $student) => ['id' => $student->id, 'name' => $student->full_name])
            ->values();

        return response()->json(['data' => [
            'users' => StaffMemberResource::collection($colleagues)->resolve($request),
            'students' => $students,
        ]]);
    }

    public function store(PersonalEventRequest $request): JsonResponse
    {
        $user = $request->user();

        $event = DB::transaction(function () use ($request, $user): CalendarEvent {
            $event = CalendarEvent::create([
                ...SharedEvents::attributes($request->validated()),
                'type' => CalendarEventType::Personal->value,
                'created_by_id' => $user->id,
                'student_id' => $request->validated('student_id'),
            ]);

            $this->participants->addOwner($event, $user);
            $this->participants->invite($event, $this->invitees($request), $user);

            return $event;
        });

        return response()->json(['data' => $this->sharedEvents->item($event->fresh(), $user)], 201);
    }

    public function update(PersonalEventRequest $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $user = $request->user();
        $current = $this->sharedEvents->item($calendarEvent, $user);
        $validated = $request->validated();

        DB::transaction(function () use ($request, $calendarEvent, $current, $validated, $user): void {
            $calendarEvent->update([
                ...SharedEvents::attributes([
                    'title' => $current['title'],
                    'date' => $current['date'],
                    'time' => $current['time'],
                    'location' => $current['place'],
                    'description' => $current['detail'],
                    ...$validated,
                ]),
                'student_id' => array_key_exists('student_id', $validated)
                    ? $validated['student_id']
                    : $calendarEvent->student_id,
            ]);

            if ($request->inviteeIds() !== null) {
                $this->participants->syncInvitees($calendarEvent, $this->invitees($request), $user);
            }
        });

        return response()->json(['data' => $this->sharedEvents->item($calendarEvent->fresh(), $user)]);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): Response
    {
        // Not on the caller's calendar → it does not exist for them.
        abort_unless($request->user()->can('view', $calendarEvent), 404);
        abort_unless($calendarEvent->isPersonal(), 403);
        $this->authorize('delete', $calendarEvent);

        $this->participants->cancel($calendarEvent, $request->user());

        return response()->noContent();
    }

    /**
     * «Quitar de mi calendario»: an invitee leaves; the event stays for the
     * others. Also works for a meeting someone was invited to.
     */
    public function leave(Request $request, CalendarEvent $calendarEvent): Response
    {
        abort_unless($request->user()->can('view', $calendarEvent), 404);
        $this->authorize('leave', $calendarEvent);

        $this->participants->remove($calendarEvent, $request->user());

        return response()->noContent();
    }

    /**
     * @return iterable<User>
     */
    protected function invitees(PersonalEventRequest $request): iterable
    {
        $ids = $request->inviteeIds() ?? [];

        return $ids === []
            ? []
            : User::query()->where('school_id', $request->user()->school_id)->whereKey($ids)->get();
    }
}
