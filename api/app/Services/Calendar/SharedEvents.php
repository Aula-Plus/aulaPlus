<?php

namespace App\Services\Calendar;

use App\Enums\CalendarItemType;
use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\Student;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The events that live in people's own calendars (ClickUp 1dfd3mc56kp,
 * P21-DEC-108): personal events — and, once screen 10 schedules them,
 * meetings. Each one is stored once and reaches only the calendars it is
 * linked to, so it appears once in the feed of each participant and never
 * for anybody else (screen 21 red line). Feed items of type `meeting`.
 *
 * Every participant sees the whole event: what, when, where, the detail and
 * who else takes part. If it names a student, every participant sees the
 * name (decision of 21/11), but `student.can_view` — the StudentPolicy of
 * whoever is looking — decides whether the file may be opened: an invitation
 * never widens access to a student.
 */
class SharedEvents
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function for(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $calendarId = Calendar::query()->where('user_id', $user->id)->value('id');
        if ($calendarId === null) {
            return collect();
        }

        $events = CalendarEvent::query()
            ->linkedToCalendars()
            ->whereHas('calendars', fn ($query) => $query->whereKey($calendarId))
            ->where('start_at', '>=', SchoolTime::startOfDay($from))
            ->where('start_at', '<', SchoolTime::startOfDay($to->addDay()))
            ->with(['calendars.user', 'createdBy', 'student'])
            ->get();

        $canView = [];

        return $events->map(fn (CalendarEvent $event): array => $this->item($event, $user, $canView))->values();
    }

    /**
     * One event as a feed item, for the viewer. `$canView` caches the
     * StudentPolicy answer per student across a feed.
     *
     * @param  array<int, bool>  $canView
     * @return array<string, mixed>
     */
    public function item(CalendarEvent $event, User $viewer, array &$canView = []): array
    {
        $event->loadMissing(['calendars.user', 'createdBy', 'student']);
        $local = CarbonImmutable::instance($event->start_at)->setTimezone(SchoolTime::timezone());
        $end = $event->end_at ? CarbonImmutable::instance($event->end_at)->setTimezone(SchoolTime::timezone()) : null;

        /** @var Student|null $student */
        $student = $event->student;
        if ($student !== null && ! array_key_exists($student->id, $canView)) {
            $canView[$student->id] = $viewer->can('view', $student);
        }

        $participants = $event->calendars
            ->map(fn (Calendar $calendar) => $calendar->user)
            ->filter()
            ->sortBy(fn (User $user) => [$user->id === $event->created_by_id ? 0 : 1, $user->name])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();

        return [
            'key' => 'event-'.$event->id,
            'type' => CalendarItemType::Meeting->value,
            'id' => $event->id,
            'date' => $local->toDateString(),
            'time' => $event->all_day ? null : $local->format('H:i'),
            'end_time' => $event->all_day || $end === null ? null : $end->format('H:i'),
            'title' => $event->title,
            'detail' => $event->description,
            'group' => null,
            'subject' => null,
            'place' => $event->location,
            'assessment_type' => null,
            'can_edit' => $viewer->can('update', $event),
            'shared' => [
                'kind' => $event->type,
                'owner' => $event->createdBy ? ['id' => $event->createdBy->id, 'name' => $event->createdBy->name] : null,
                'is_owner' => $event->created_by_id === $viewer->id,
                // Same rule as CalendarEventPolicy::leave, from the loaded
                // participants (no query per event); the endpoint re-checks.
                'can_leave' => $event->created_by_id !== $viewer->id
                    && collect($participants)->contains('id', $viewer->id),
                'participants' => $participants,
                'student' => $student === null ? null : [
                    'id' => $student->id,
                    'name' => $student->full_name,
                    'can_view' => $canView[$student->id],
                ],
            ],
        ];
    }

    /**
     * School-local date + optional time → stored instant (app timezone), the
     * same convention as institutional events.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $time = $data['time'] ?? null;
        $startAt = $time === null
            ? SchoolTime::startOfDay($data['date'])
            : CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$time, SchoolTime::timezone())
                ->setTimezone((string) config('app.timezone'));

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'start_at' => $startAt,
            'end_at' => null,
            'all_day' => $time === null,
        ];
    }
}
