<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * «Te sumaron a un evento» (ClickUp 1dfd3mc56kp): someone added you to an
 * event of their calendar, which already appears in yours — there is nothing
 * to accept. Sent by App\Services\Calendar\EventParticipants, so a meeting
 * of screen 10 reaches its invitees the same way.
 *
 * The payload names the event (what it is and its school-local date) and who
 * added you, like the screening notices carry their type's name. Never the
 * student the event may name, nor its description (CLAUDE.md rule 11): the
 * Calendar shows those through the authorized feed.
 */
class CalendarEventInvitedNotification extends Notification
{
    public function __construct(
        protected CalendarEvent $event,
        protected ?User $invitedBy = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $local = CarbonImmutable::instance($this->event->start_at)->setTimezone(SchoolTime::timezone());

        return [
            'type' => 'calendar_event_invited',
            'calendar_event_id' => $this->event->id,
            'event_type' => $this->event->type,
            'title' => $this->event->title,
            'date' => $local->toDateString(),
            'time' => $this->event->all_day ? null : $local->format('H:i'),
            'invited_by_id' => $this->invitedBy?->id,
            'invited_by_name' => $this->invitedBy?->name,
        ];
    }
}
