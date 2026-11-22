<?php

namespace App\Http\Requests;

use App\Models\CalendarEvent;
use App\Models\Student;
use App\Services\Calendar\EventParticipants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST /personal-events) or edit (PATCH /personal-events/{event}) an
 * event of the caller's own calendar (ClickUp 1dfd3mc56kp): what, a date, an
 * optional time, place and detail; the people invited (any active user of
 * the caller's school) and, optionally, one student.
 *
 * - `invitee_ids`, when sent, is the full list of invitees (the creator is
 *   always in). Users are not tenant-scoped, so the school is pinned here.
 *   New invitees must be active; people already in the event are kept even
 *   if deactivated since (they are not notified again).
 * - `student_id`: a student of the school the creator can see — an event
 *   cannot be used to reveal a student the creator does not already see.
 *
 * Only the creator edits (CalendarEventPolicy). Dates and times are
 * school-local.
 */
class PersonalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->event();

        if ($event === null) {
            return $this->user()->can('createPersonal', CalendarEvent::class);
        }

        // An event not on the caller's calendar does not exist for them.
        abort_unless($this->user()->can('view', $event), 404);

        return $event->isPersonal() && $this->user()->can('update', $event);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $schoolId = $this->user()->school_id;

        return [
            'title' => [$required, 'string', 'max:200'],
            'date' => [$required, 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'invitee_ids' => ['sometimes', 'array', 'max:100'],
            'invitee_ids.*' => [
                'integer',
                'distinct',
                Rule::notIn([$this->user()->id]),
                // An active colleague: same school, not deactivated, already
                // set a password (a pending invitation cannot log in yet).
                // Someone who is already in the event stays valid even if
                // deactivated since: keeping them must not block editing the
                // event; only new invitees have to be active.
                Rule::exists('users', 'id')
                    ->where('school_id', $schoolId)
                    ->where(fn ($query) => $query
                        ->where(fn ($active) => $active->whereNull('disabled_at')->whereNotNull('password'))
                        ->orWhereIn('id', $this->currentParticipantIds())),
            ],
            'student_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('students', 'id')->where('school_id', $schoolId),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('student_id')) {
                return;
            }

            $studentId = $this->input('student_id');
            if ($studentId === null || (int) $studentId === $this->event()?->student_id) {
                return;
            }

            $student = Student::query()->find((int) $studentId);
            if ($student === null || ! $this->user()->can('view', $student)) {
                $validator->errors()->add('student_id', 'The selected student is invalid.');
            }
        }];
    }

    /**
     * @return list<int> who is in the event being edited (none on create)
     */
    protected function currentParticipantIds(): array
    {
        $event = $this->event();

        return $event === null ? [] : app(EventParticipants::class)->participantIds($event);
    }

    public function event(): ?CalendarEvent
    {
        $event = $this->route('calendarEvent');

        return $event instanceof CalendarEvent ? $event : null;
    }

    /**
     * @return list<int>|null null when the invitees were not sent
     */
    public function inviteeIds(): ?array
    {
        $ids = $this->validated('invitee_ids');

        return $ids === null ? null : array_values(array_map('intval', $ids));
    }
}
