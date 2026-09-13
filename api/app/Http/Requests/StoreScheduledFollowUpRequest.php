<?php

namespace App\Http\Requests;

use App\Models\ScheduledFollowUp;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a scheduled follow-up on a student. Authorization delegates to
 * ScheduledFollowUpPolicy::create (same school AND teaches-the-student or a
 * school-wide role) — see that policy for why this is stricter than
 * Accommodation's role-only create.
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
        ];
    }
}
