<?php

namespace App\Services\Calendar;

use App\Enums\CalendarItemType;
use App\Enums\Role;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\ClassSession;
use App\Models\ScheduledFollowUp;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The Calendar (screen 21): orders in time dates that already exist in other
 * screens (P21-DEC-02). Nothing here is a copy — each item is read from its
 * own record, and the only date stored for the Calendar itself is Direction's
 * institutional event.
 *
 * Each person sees their own dates, never more than they already see
 * elsewhere (screen 21 red line):
 *
 * - Classes: only teachers, of the groups (and subject) they teach.
 * - Assessments: a teacher, the ones they own; Psychopedagogy and Direction,
 *   all of the school (to anticipate loaded weeks, never to look at a case —
 *   P21-DEC-04: no scores, no student).
 * - Tasks: the caller's own pending follow-ups with a deadline (same rows as
 *   «Mis seguimientos»).
 * - Institutional events: everyone in the school.
 * - Meetings, period closings, project stages: no source yet (screens 10, 26,
 *   18), always empty. A meeting, when it exists, reaches only the calendars
 *   it is linked to.
 *
 * Items never carry a student's name.
 */
class CalendarFeed
{
    /**
     * @param  list<CalendarItemType>  $types
     * @return Collection<int, array<string, mixed>>
     */
    public function for(User $user, CarbonImmutable $from, CarbonImmutable $to, array $types): Collection
    {
        $wants = fn (CalendarItemType $type): bool => in_array($type, $types, true);

        $items = collect();

        if ($wants(CalendarItemType::ClassSession)) {
            $items = $items->concat($this->classSessions($user, $from, $to));
        }

        if ($wants(CalendarItemType::Assessment)) {
            $items = $items->concat($this->assessments($user, $from, $to));
        }

        if ($wants(CalendarItemType::Task)) {
            $items = $items->concat($this->tasks($user, $from, $to));
        }

        if ($wants(CalendarItemType::Institutional)) {
            $items = $items->concat($this->institutionalEvents($user, $from, $to));
        }

        // Within a day: dates without a time first (like all-day events), then
        // by time; ties keep a stable order by type and id.
        return $items
            ->sortBy(fn (array $item): string => $item['date'].' '.($item['time'] ?? '00:00').($item['time'] === null ? '0' : '1').' '.$item['type'].' '.str_pad((string) $item['id'], 10, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function classSessions(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if (! $user->hasRole(Role::Teacher->value)) {
            return collect();
        }

        return ClassSession::query()
            ->with(['group:id,name', 'subject:id,name'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            // Groups the teacher leads; when the class names its subject, only
            // if the teacher is assigned that subject in the group.
            ->whereExists(function (QueryBuilder $query) use ($user): void {
                $query->selectRaw('1')
                    ->from('group_teacher')
                    ->whereColumn('group_teacher.group_id', 'class_sessions.group_id')
                    ->where('group_teacher.teacher_id', $user->id)
                    ->where(fn (QueryBuilder $inner) => $inner
                        ->whereNull('class_sessions.subject_id')
                        ->orWhereColumn('group_teacher.subject_id', 'class_sessions.subject_id'));
            })
            ->get()
            ->map(fn (ClassSession $session): array => [
                'key' => 'class-'.$session->id,
                'type' => CalendarItemType::ClassSession->value,
                'id' => $session->id,
                'date' => $session->date->toDateString(),
                'time' => $session->start_time,
                'end_time' => $session->start_time === null
                    ? null
                    : CarbonImmutable::createFromFormat('H:i', $session->start_time)->addMinutes($session->duration_minutes)->format('H:i'),
                'title' => $session->subject?->name ?? $session->title,
                'detail' => $session->title,
                'group' => $session->group ? ['id' => $session->group->id, 'name' => $session->group->name] : null,
                'subject' => $session->subject ? ['id' => $session->subject->id, 'name' => $session->subject->name] : null,
                'place' => $session->room,
                'assessment_type' => null,
                // Every class returned above passes ClassSessionPolicy::update
                // (the same group_teacher + subject condition as isOwner), so
                // no per-row policy query (N+1). Keep both rules in sync:
                // CalendarFeedTest checks them against each other.
                'can_edit' => true,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function assessments(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $schoolWide = $user->hasAnyRole(Role::schoolWideValues());

        return Assessment::query()
            ->with(['group:id,name', 'subject:id,name'])
            ->whereDate('administered_at', '>=', $from->toDateString())
            ->whereDate('administered_at', '<=', $to->toDateString())
            ->when(! $schoolWide, fn (Builder $query) => $query->where('teacher_id', $user->id))
            ->get()
            ->map(fn (Assessment $assessment): array => [
                'key' => 'assessment-'.$assessment->id,
                'type' => CalendarItemType::Assessment->value,
                'id' => $assessment->id,
                'date' => $assessment->administered_at->toDateString(),
                'time' => null,
                'end_time' => null,
                'title' => $assessment->purpose,
                'detail' => $assessment->subject?->name,
                'group' => $assessment->group ? ['id' => $assessment->group->id, 'name' => $assessment->group->name] : null,
                'subject' => $assessment->subject ? ['id' => $assessment->subject->id, 'name' => $assessment->subject->name] : null,
                'place' => null,
                'assessment_type' => $assessment->type->value,
                'can_edit' => false,
            ]);
    }

    /**
     * The caller's own pending follow-ups whose deadline falls in the window —
     * the same rows as «Mis seguimientos», re-checked against the policy. Only
     * the follow-up's own title travels; never the student.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function tasks(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ScheduledFollowUp::query()
            ->with('student')
            ->where('resolved', false)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from->toDateString())
            ->whereDate('due_date', '<=', $to->toDateString())
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->whereNull('hidden_at'))
            ->get()
            ->filter(fn (ScheduledFollowUp $followUp): bool => $user->can('view', $followUp))
            ->map(fn (ScheduledFollowUp $followUp): array => [
                'key' => 'task-'.$followUp->id,
                'type' => CalendarItemType::Task->value,
                'id' => $followUp->id,
                'date' => $followUp->due_date->toDateString(),
                'time' => null,
                'end_time' => null,
                'title' => $followUp->title,
                'detail' => null,
                'group' => null,
                'subject' => null,
                'place' => null,
                'assessment_type' => null,
                'can_edit' => false,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function institutionalEvents(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CalendarEvent::query()
            ->institutional()
            ->where('start_at', '>=', SchoolTime::startOfDay($from))
            ->where('start_at', '<', SchoolTime::startOfDay($to->addDay()))
            ->get()
            ->map(function (CalendarEvent $event) use ($user): array {
                $local = CarbonImmutable::instance($event->start_at)->setTimezone(SchoolTime::timezone());
                $end = $event->end_at ? CarbonImmutable::instance($event->end_at)->setTimezone(SchoolTime::timezone()) : null;

                return [
                    'key' => 'institutional-'.$event->id,
                    'type' => CalendarItemType::Institutional->value,
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
                    'can_edit' => $user->can('update', $event),
                ];
            });
    }
}
