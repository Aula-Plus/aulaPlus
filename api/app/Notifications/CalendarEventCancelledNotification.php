<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * «Se canceló…» (ClickUp 1dfd3mc56kp, Eitán 26/11): the creator of a shared
 * event deleted it, so it left the calendar of each invitee. Sent by
 * App\Services\Calendar\EventParticipants::cancel to every participant but the
 * creator.
 *
 * The payload is a snapshot taken before the event is deleted: what it was,
 * its school-local date and time, and who cancelled it. Never the student the
 * event may name, nor its description (CLAUDE.md rule 11).
 */
class CalendarEventCancelledNotification extends Notification
{
    /** @var array<string, mixed> */
    protected array $payload;

    public function __construct(CalendarEvent $event, ?User $cancelledBy = null)
    {
        $local = CarbonImmutable::instance($event->start_at)->setTimezone(SchoolTime::timezone());

        $this->payload = [
            'type' => 'calendar_event_cancelled',
            'event_type' => $event->type,
            'title' => $event->title,
            'date' => $local->toDateString(),
            'time' => $event->all_day ? null : $local->format('H:i'),
            'cancelled_by_id' => $cancelledBy?->id,
            'cancelled_by_name' => $cancelledBy?->name,
        ];
    }

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
        return $this->payload;
    }
}
