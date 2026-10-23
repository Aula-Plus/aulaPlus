<?php

namespace App\Http\Requests;

use App\Models\Alert;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Create a scheduled follow-up on a student. Authorization delegates to
 * ScheduledFollowUpPolicy::create (same school AND teaches-the-student or a
 * school-wide role) — see that policy for why this is stricter than
 * Accommodation's role-only create.
 *
 * The responsible person and the people it is shared with must themselves be
 * able to see the student (same policy rule): a follow-up can go up to
 * psychopedagogy or direction, never to someone with no access to the case.
 * There is no cap on how many people follow it.
 *
 * Optionally linked to an alert about the same student («Crear seguimiento
 * vinculado», ClickUp 86e3jpzdp review of 14/10): the caller must also be
 * allowed to act on that alert (AlertPolicy::linkFollowUp — whoever handed
 * it off only reads it).
 */
class StoreScheduledFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        if (! $this->user()->can('create', [ScheduledFollowUp::class, $student])) {
            return false;
        }

        // A malformed or unknown alert id is left to the validation rules
        // (422); an alert the caller may not act on is a 403.
        $alertId = $this->input('alert_id');
        if (is_int($alertId) || (is_string($alertId) && ctype_digit($alertId))) {
            $alert = Alert::query()->find((int) $alertId);
            if ($alert !== null && ! $this->user()->can('linkFollowUp', $alert)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Student $student */
        $student = $this->route('student');

        return [
            // Required for new follow-ups; nullable in the DB only for the
            // rows backfilled by the stages migration (ClickUp 86e3qkwna).
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string'],
            'due_date' => ['required', 'date'],
            'alert_id' => [
                'nullable', 'integer',
                Rule::exists('alerts', 'id')
                    ->where('school_id', $student->school_id)
                    ->where('student_id', $student->id),
            ],
            'assigned_to_id' => ['nullable', 'integer', $this->activeColleague($student)],
            'shared_with_ids' => ['nullable', 'array', 'max:50'],
            'shared_with_ids.*' => ['integer', 'distinct', $this->activeColleague($student)],
        ];
    }

    /**
     * An existing, not deactivated user of the student's own school. User is
     * not tenant-scoped, so the school is pinned here explicitly (defence in
     * depth before the policy check in `after()`); a deactivated person can
     * no longer log in, so a follow-up handed to them would be lost.
     */
    protected function activeColleague(Student $student): Exists
    {
        return Rule::exists('users', 'id')
            ->where('school_id', $student->school_id)
            ->whereNull('disabled_at');
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Student $student */
            $student = $this->route('student');
            $policy = app(ScheduledFollowUpPolicy::class);

            $assignedId = $this->input('assigned_to_id');
            if ($assignedId !== null && ! $this->isFollower($policy, $student, (int) $assignedId)) {
                $validator->errors()->add('assigned_to_id', 'The selected person cannot follow this student.');
            }

            foreach ((array) $this->input('shared_with_ids', []) as $userId) {
                if (! $this->isFollower($policy, $student, (int) $userId)) {
                    $validator->errors()->add('shared_with_ids', 'One of the selected people cannot follow this student.');
                    break;
                }
            }
        }];
    }

    protected function isFollower(ScheduledFollowUpPolicy $policy, Student $student, int $userId): bool
    {
        // User is not tenant-scoped (see CLAUDE.md), so the school match is
        // part of the policy check, not of this lookup.
        $user = User::query()->find($userId);

        return $user !== null && $policy->canFollow($user, $student);
    }
}
