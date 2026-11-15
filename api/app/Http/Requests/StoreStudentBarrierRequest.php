<?php

namespace App\Http\Requests;

use App\Models\Accommodation;
use App\Models\Barrier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registering a learning barrier for a student from «Ajustes y PTP» (screen
 * 06, ClickUp 1dfd3mc4bgc): psychopedagogy only — it is the screen's own
 * action, and BarrierPolicy::create still has the last word. The {student} is
 * resolved through the SchoolScope, so another school's student 404s first.
 * `description` and `coping_strategy` are sensitive (rule 11): validated
 * here, never logged (the model keeps them out of the audit diff).
 */
class StoreStudentBarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewInbox', Accommodation::class)
            && $this->user()->can('create', Barrier::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
            'coping_strategy' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
