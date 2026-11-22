<?php

namespace App\Services\Calendar;

use App\Models\Calendar;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Notifications\CalendarEventInvitedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who an event of people's own calendars belongs to (ClickUp 1dfd3mc56kp,
 * P21-DEC-108): the event is stored once and linked to the calendar of each
 * participant (calendar_calendar_event pivot), so it appears once in each of
 * them. Being added reaches the person directly — there is nothing to
 * accept — with a notice (screen 22).
 *
 * Reusable by any event linked to calendars: personal events today, the
 * meetings of the Psychopedagogy space (screen 10) later («el encuentro
 * aparece en el calendario de cada presente y les llega un aviso, como
 * cualquier evento compartido»).
 *
 * Callers validate who may be added (active users of the event's own
 * school); this service re-checks the school as defence in depth.
 */
class EventParticipants
{
    /**
     * The person's own calendar (1:1), created on first use.
     *
     * Two requests may create it at once (two people inviting the same
     * colleague): `calendars.user_id` is unique, so the loser of the race
     * reads the winner's row instead of failing. The insert runs in its own
     * (nested) transaction — a savepoint — because a failed statement
     * aborts the surrounding Postgres transaction otherwise.
     *
     * The queries skip SchoolScope on purpose: `user_id` is globally unique
     * and the school is pinned from the user itself (and re-checked by
     * assertSameSchool), so this also works where no tenant is set.
     */
    public function calendarOf(User $user): Calendar
    {
        $existing = fn (): ?Calendar => Calendar::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();

        $calendar = $existing();
        if ($calendar !== null) {
            return $calendar;
        }

        try {
            return DB::transaction(function () use ($user): Calendar {
                $calendar = new Calendar(['user_id' => $user->id]);
                $calendar->school_id = $user->school_id;
                $calendar->save();

                return $calendar;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }
    }

    /**
     * Put the event on its creator's calendar (no notice: it is theirs).
     */
    public function addOwner(CalendarEvent $event, User $owner): void
    {
        $this->assertSameSchool($event, $owner);
        $this->calendarOf($owner)->events()->syncWithoutDetaching([$event->id]);
    }

    /**
     * Add people to the event; each one not already in it gets the notice.
     *
     * @param  iterable<User>  $users
     * @return Collection<int, User> the ones actually added
     */
    public function invite(CalendarEvent $event, iterable $users, ?User $invitedBy = null): Collection
    {
        $current = $this->participantIds($event);
        $added = collect();

        foreach ($users as $user) {
            $this->assertSameSchool($event, $user);
            if (in_array($user->id, $current, true) || $added->contains('id', $user->id)) {
                continue;
            }

            $this->calendarOf($user)->events()->syncWithoutDetaching([$event->id]);
            $added->push($user);
        }

        foreach ($added as $user) {
            $user->notify(new CalendarEventInvitedNotification($event, $invitedBy));
        }

        return $added;
    }

    /**
     * Make the invitees exactly `$users` (the creator always stays): new ones
     * are added with a notice, missing ones are removed from the event.
     *
     * @param  iterable<User>  $users
     */
    public function syncInvitees(CalendarEvent $event, iterable $users, ?User $invitedBy = null): void
    {
        $users = collect($users);
        $keep = $users->pluck('id')->push($event->created_by_id)->filter()->all();

        $removed = Calendar::query()
            ->withoutGlobalScopes()
            ->whereNotIn('user_id', $keep)
            ->whereHas('events', fn ($query) => $query->whereKey($event->id))
            ->pluck('id');
        if ($removed->isNotEmpty()) {
            $event->calendars()->detach($removed->all());
        }

        $this->invite($event, $users, $invitedBy);
    }

    /**
     * «Quitar de mi calendario»: the event stays for everyone else.
     */
    public function remove(CalendarEvent $event, User $user): void
    {
        $calendar = Calendar::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();
        $calendar?->events()->detach($event->id);
    }

    /**
     * @return list<int>
     */
    public function participantIds(CalendarEvent $event): array
    {
        return Calendar::query()
            ->withoutGlobalScopes()
            ->whereHas('events', fn ($query) => $query->whereKey($event->id))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function assertSameSchool(CalendarEvent $event, User $user): void
    {
        if ($user->school_id !== $event->school_id) {
            throw new \InvalidArgumentException('A participant must belong to the event\'s school.');
        }
    }
}
