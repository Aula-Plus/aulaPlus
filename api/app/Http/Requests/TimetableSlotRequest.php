<?php

namespace App\Http\Requests;

use App\Casts\TimeOfDay;
use App\Models\Group;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST /timetable-slots) or edit (PATCH /timetable-slots/{slot}) a
 * line of the caller's own «Mis horarios». The group-subject must be one the
 * teacher teaches (group_teacher). Owner only — TimetableSlotPolicy.
 */
class TimetableSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TimetableSlot|null $slot */
        $slot = $this->route('timetableSlot');

        return $slot === null
            ? $this->user()->can('create', TimetableSlot::class)
            : $this->user()->can('update', $slot);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $schoolId = Tenancy::schoolId();
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        /** @var TimetableSlot|null $slot */
        $slot = $this->route('timetableSlot');

        return [
            'group_id' => [$required, 'integer', Rule::exists('groups', 'id')->where('school_id', $schoolId)],
            'subject_id' => [$required, 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId),
                function (string $attribute, mixed $value, \Closure $fail) use ($slot): void {
                    $group = Group::find($this->input('group_id', $slot?->group_id));
                    $subject = Subject::find($value);
                    if ($group === null || $subject === null || ! $this->user()->teachesSubjectInGroup($group, $subject)) {
                        $fail('You are not assigned to teach this subject in this group.');
                    }
                }],
            'weekday' => [$required, 'integer', 'between:1,7'],
            'start_time' => [$required, 'date_format:H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    /**
     * The same group-subject cannot have the same weekday and time twice.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var TimetableSlot|null $slot */
            $slot = $this->route('timetableSlot');

            $duplicate = TimetableSlot::query()
                ->where('teacher_id', $this->user()->id)
                ->where('group_id', $this->input('group_id', $slot?->group_id))
                ->where('subject_id', $this->input('subject_id', $slot?->subject_id))
                ->where('weekday', $this->input('weekday', $slot?->weekday))
                ->where('start_time', TimeOfDay::normalize((string) $this->input('start_time', $slot?->start_time)))
                ->when($slot, fn ($query) => $query->whereKeyNot($slot->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('start_time', 'This group and subject already has a slot on that day and time.');
            }
        }];
    }

    /**
     * A PATCH that changes only the group must still check the (unchanged)
     * subject against the new group.
     */
    protected function prepareForValidation(): void
    {
        /** @var TimetableSlot|null $slot */
        $slot = $this->route('timetableSlot');

        if ($slot !== null && $this->has('group_id') && ! $this->has('subject_id')) {
            $this->merge(['subject_id' => $slot->subject_id]);
        }
    }
}
