<?php

namespace App\Http\Requests\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\ClassSession;
use App\Support\SchoolTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Closing a class is one tap, not a form (P15-DEC-03, screen 15 P15-PRO-05):
 * «¿Se dio lo que planificaste?» Todo, Una parte or Nada. With «Una parte»
 * the teacher marks which of the class's objectives were given. Optional:
 * an unclosed class keeps its objectives as planned. It is enabled from the
 * day of the class, never for a cancelled one, and only the owning teacher
 * closes it (ClassSessionPolicy::close).
 */
class CloseClassSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('close', $this->classSession());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'result' => ['required', new Enum(ObjectiveCoverage::class)],
            'given_objective_ids' => ['nullable', 'array', 'prohibited_unless:result,'.ObjectiveCoverage::Partial->value],
            'given_objective_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $class = $this->classSession();

                if ($class->status === ClassSessionStatus::Cancelled) {
                    $validator->errors()->add('result', 'A cancelled class cannot be closed.');

                    return;
                }
                // Compared on the school's calendar day, not UTC's.
                if ($class->date->toDateString() > SchoolTime::today()->toDateString()) {
                    $validator->errors()->add('result', 'A class can be closed from its day on.');

                    return;
                }

                $linked = $class->unitObjectives()->pluck('unit_objectives.id')->map(fn ($id) => (int) $id);
                $given = collect((array) $this->input('given_objective_ids', []))->map(fn ($id) => (int) $id);
                if ($given->diff($linked)->isNotEmpty()) {
                    $validator->errors()->add('given_objective_ids', 'Only objectives of this class can be marked as given.');
                }
            },
        ];
    }

    public function classSession(): ClassSession
    {
        /** @var ClassSession */
        return $this->route('classSession');
    }
}
