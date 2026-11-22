<?php

namespace App\Http\Requests;

use App\Enums\PsychopedagogyMeetingKind;
use App\Models\PsychopedagogyMeeting;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Agendar encuentro» (POST) or edit one (PUT): day, time, kind and with whom
 * (screen 10 › Agenda). An individual session is with one student; a meeting
 * is of one of the school's sheet types and may concern a group and/or a
 * student. Psychopedagogy only.
 */
class PsychopedagogyMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PsychopedagogyMeeting|null $meeting */
        $meeting = $this->route('meeting');

        return $meeting === null
            ? $this->user()->can('create', PsychopedagogyMeeting::class)
            : $this->user()->can('update', $meeting);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $school = Tenancy::schoolId();
        $individual = PsychopedagogyMeetingKind::IndividualSession->value;

        return [
            'kind' => ['required', Rule::enum(PsychopedagogyMeetingKind::class)],
            'meeting_sheet_type_id' => [
                "exclude_if:kind,{$individual}", 'required', 'integer',
                Rule::exists('meeting_sheet_types', 'id')->where('school_id', $school),
            ],
            'scheduled_on' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'student_id' => [
                "required_if:kind,{$individual}", 'nullable', 'integer',
                Rule::exists('students', 'id')->where('school_id', $school)->whereNull('deleted_at'),
            ],
            'group_id' => [
                "exclude_if:kind,{$individual}", 'nullable', 'integer',
                Rule::exists('groups', 'id')->where('school_id', $school),
            ],
            'attendee_ids' => ['sometimes', 'array', 'max:30'],
            'attendee_ids.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('school_id', $school)->whereNull('disabled_at'),
            ],
            'external_attendees' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }
}
