<?php

namespace App\Http\Requests;

use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a scheduled follow-up on a student. Authorization delegates to
 * ScheduledFollowUpPolicy::create (same school AND teaches-the-student or a
 * school-wide role) — see that policy for why this is stricter than
 * Accommodation's role-only create.
 *
 * The responsible person and the people it is shared with must themselves be
 * able to see the student (same policy rule): a follow-up can go up to
 * psychopedagogy or direction, never to someone with no access to the case.
 */
class StoreScheduledFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        return $this->user()->can('create', [ScheduledFollowUp::class, $student]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string'],
            'due_date' => ['required', 'date'],
            'assigned_to_id' => ['nullable', 'integer'],
            'shared_with_ids' => ['nullable', 'array', 'max:20'],
            'shared_with_ids.*' => ['integer', 'distinct'],
        ];
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
