<?php

namespace App\Services\Calendar;

use App\Enums\CalendarEventType;
use App\Models\CalendarEvent;
use App\Models\PsychopedagogyMeeting;
use App\Models\User;

/**
 * A meeting of psychopedagogy's agenda (screen 10) in people's calendars
 * (ClickUp 1dfd3mc56kp, P21-DEC-01): «si te citaron a una reunión, te llega
 * por calendario y por notificación». The same way of sharing as any event
 * of one's own calendar — one CalendarEvent of type `meeting`, linked to the
 * calendar of whoever scheduled it and of each person cited, each one
 * notified once (EventParticipants).
 *
 * What it carries is what the agenda row already says: its kind (the sheet
 * type's name, or «Sesión individual»), day and time, and the student it is
 * about, if any — every participant sees the name, the file opens only for
 * whoever could already open it (SharedEvents, decision 3B; screen 21 red
 * line: only the people cited see it). Never the meeting's notes or sheet.
 *
 * Only the people added since the last save are notified, and only the ones
 * taken out of the meeting are removed: someone who used «Quitar de mi
 * calendario» is not added back by an unrelated edit. The event is edited
 * only from the agenda — the personal-event endpoints refuse it.
 */
class MeetingEvents
{
    /**
     * Title of an individual session (stored as the event's title, like the
     * sheet types' names, which are the school's data in Spanish).
     */
    public const INDIVIDUAL_SESSION_TITLE = 'Sesión individual';

    public function __construct(protected EventParticipants $participants) {}

    /**
     * Create or update the meeting's event after the meeting and its
     * attendees were saved. `$previousAttendeeIds` = the attendees before
     * this save (empty on create).
     *
     * @param  list<int>  $previousAttendeeIds
     */
    public function sync(PsychopedagogyMeeting $meeting, User $actor, array $previousAttendeeIds = []): CalendarEvent
    {
        $meeting->load('sheetType');

        $attributes = [
            ...SharedEvents::attributes([
                'title' => $meeting->isIndividualSession() || $meeting->sheetType === null
                    ? self::INDIVIDUAL_SESSION_TITLE
                    : $meeting->sheetType->name,
                'date' => $meeting->scheduled_on->toDateString(),
                'time' => $meeting->start_time,
            ]),
            'student_id' => $meeting->student_id,
        ];

        $event = $meeting->calendarEvent;

        if ($event === null) {
            $owner = $meeting->created_by_id !== null
                ? User::query()->where('school_id', $meeting->school_id)->find($meeting->created_by_id)
                : null;
            $owner ??= $actor;

            $event = CalendarEvent::create([
                ...$attributes,
                'type' => CalendarEventType::Meeting->value,
                'created_by_id' => $owner->id,
            ]);
            $meeting->forceFill(['calendar_event_id' => $event->id])->save();
            $this->participants->addOwner($event, $owner);
            // A meeting from before this link existed: everyone cited is new.
            $previousAttendeeIds = [];
        } else {
            $event->update($attributes);
        }

        $currentIds = $meeting->attendees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $removedIds = array_values(array_diff($previousAttendeeIds, $currentIds, [$event->created_by_id]));
        foreach ($this->users($meeting, $removedIds) as $user) {
            $this->participants->remove($event, $user);
        }

        $addedIds = array_values(array_diff($currentIds, $previousAttendeeIds));
        $this->participants->invite($event, $this->users($meeting, $addedIds), $actor);

        return $event;
    }

    /**
     * @param  list<int>  $ids
     * @return iterable<User>
     */
    protected function users(PsychopedagogyMeeting $meeting, array $ids): iterable
    {
        return $ids === []
            ? []
            : User::query()->where('school_id', $meeting->school_id)->whereKey($ids)->get();
    }
}
