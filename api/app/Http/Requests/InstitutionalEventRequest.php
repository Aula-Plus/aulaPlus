<?php

namespace App\Http\Requests;

use App\Models\CalendarEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST /calendar-events) or edit (PATCH /calendar-events/{event}) an
 * institutional event: what it is, a date, an optional time, an optional
 * place and description (P21-DEC-05). Direction only — CalendarEventPolicy.
 * Dates and times are school-local.
 */
class InstitutionalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CalendarEvent|null $event */
        $event = $this->route('calendarEvent');

        if ($event === null) {
            return $this->user()->can('create', CalendarEvent::class);
        }

        // A meeting the caller is not invited to does not exist for them
        // (screen 21 red line): 404, not 403.
        abort_unless($this->user()->can('view', $event), 404);

        // This endpoint edits institutional events only.
        return $event->isInstitutional() && $this->user()->can('update', $event);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'date' => [$required, 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
