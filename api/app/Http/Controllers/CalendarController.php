<?php

namespace App\Http\Controllers;

use App\Enums\CalendarEventType;
use App\Http\Requests\CalendarFeedRequest;
use App\Http\Requests\InstitutionalEventRequest;
use App\Models\CalendarEvent;
use App\Services\Calendar\CalendarFeed;
use App\Support\SchoolTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Calendar (screen 21, ClickUp 1dfd3mc4bgh): the feed of each person's
 * dates, and Direction's institutional events — the only date born here.
 * The Inicio's «Hoy en tu calendario» block (P01-HOY-01) reads the same feed.
 */
class CalendarController extends Controller
{
    public function index(CalendarFeedRequest $request, CalendarFeed $feed): JsonResponse
    {
        return response()->json([
            'data' => $feed->for($request->user(), $request->from(), $request->to(), $request->types()),
        ]);
    }

    public function store(InstitutionalEventRequest $request): JsonResponse
    {
        $event = CalendarEvent::create([
            ...$this->eventAttributes($request->validated()),
            'type' => CalendarEventType::Institutional->value,
            'created_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($event)], 201);
    }

    public function update(InstitutionalEventRequest $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $current = $this->present($calendarEvent);

        $calendarEvent->update($this->eventAttributes([
            'title' => $current['title'],
            'date' => $current['date'],
            'time' => $current['time'],
            'location' => $current['location'],
            'description' => $current['description'],
            ...$request->validated(),
        ]));

        return response()->json(['data' => $this->present($calendarEvent)]);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): Response
    {
        // Same as editing: an event the caller cannot see is a 404, and only
        // institutional events are removed here.
        abort_unless($request->user()->can('view', $calendarEvent), 404);
        abort_unless($calendarEvent->isInstitutional(), 403);
        $this->authorize('delete', $calendarEvent);

        $calendarEvent->delete();

        return response()->noContent();
    }

    /**
     * School-local date + optional time → stored instant (app timezone).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function eventAttributes(array $data): array
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

    /**
     * @return array<string, mixed>
     */
    protected function present(CalendarEvent $event): array
    {
        $local = CarbonImmutable::instance($event->start_at)->setTimezone(SchoolTime::timezone());

        return [
            'id' => $event->id,
            'type' => $event->type,
            'title' => $event->title,
            'date' => $local->toDateString(),
            'time' => $event->all_day ? null : $local->format('H:i'),
            'location' => $event->location,
            'description' => $event->description,
        ];
    }
}
