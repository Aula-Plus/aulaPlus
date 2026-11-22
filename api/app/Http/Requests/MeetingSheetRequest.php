<?php

namespace App\Http\Requests;

use App\Enums\ObservationScope;
use App\Models\MeetingSheet;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) or edit (PUT, «Editar antes») a meeting sheet while it is a
 * draft (P10-PLA-05): type from the school's fixed list, date, group or
 * individual, who was there, which students were discussed, what was
 * discussed, the actions (each with owner and deadline, all optional,
 * P10-DEC-08) and the proposed split — one fragment per discussed student,
 * each with its scope. Psychopedagogy only.
 */
class MeetingSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MeetingSheet|null $sheet */
        $sheet = $this->route('sheet');

        return $sheet === null
            ? $this->user()->can('create', MeetingSheet::class)
            : $this->user()->can('update', $sheet);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $school = Tenancy::schoolId();
        $activeUser = Rule::exists('users', 'id')->where('school_id', $school)->whereNull('disabled_at');

        return [
            'meeting_sheet_type_id' => ['required', 'integer', Rule::exists('meeting_sheet_types', 'id')->where('school_id', $school)],
            'psychopedagogy_meeting_id' => ['sometimes', 'nullable', 'integer', Rule::exists('psychopedagogy_meetings', 'id')->where('school_id', $school)],
            'held_on' => ['required', 'date_format:Y-m-d'],
            'group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('groups', 'id')->where('school_id', $school)],
            'attendee_ids' => ['sometimes', 'array', 'max:30'],
            'attendee_ids.*' => ['integer', 'distinct', $activeUser],
            'external_attendees' => ['sometimes', 'nullable', 'string', 'max:160'],
            'student_ids' => ['required', 'array', 'min:1', 'max:40'],
            'student_ids.*' => ['integer', 'distinct', Rule::exists('students', 'id')->where('school_id', $school)->whereNull('deleted_at')],
            'content' => ['required', 'string', 'max:20000'],
            'fragments' => ['sometimes', 'array', 'max:40'],
            'fragments.*.student_id' => ['required', 'integer', 'distinct'],
            'fragments.*.content' => ['required', 'string', 'max:10000'],
            'fragments.*.scope' => ['required', Rule::enum(ObservationScope::class)],
            'actions' => ['sometimes', 'array', 'max:30'],
            'actions.*.description' => ['required', 'string', 'max:1000'],
            'actions.*.owner_id' => ['required', 'integer', $activeUser],
            'actions.*.due_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * A fragment only goes to a student who was discussed.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $students = array_map('intval', $this->input('student_ids', []));
            foreach ($this->input('fragments', []) as $index => $fragment) {
                if (! in_array((int) $fragment['student_id'], $students, true)) {
                    $validator->errors()->add("fragments.{$index}.student_id", 'A fragment can only go to a discussed student.');
                }
            }
        }];
    }
}
