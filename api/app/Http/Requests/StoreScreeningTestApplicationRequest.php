<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Models\ScreeningTestApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /groups/{group}/screening-test-applications. Authorization
 * delegates to ScreeningTestApplicationPolicy::create (psychopedagogy only,
 * same school as the route group). The screening type must belong to the same
 * school; a guessed id from another tenant can't leak in (CLAUDE.md security
 * rules 3-4). Whether the type has an approved design in force is a
 * business-state precondition checked in the controller (422), not here.
 */
class StoreScreeningTestApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ScreeningTestApplication::class, $this->group()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'screening_test_type_id' => [
                'required',
                Rule::exists('screening_test_types', 'id')->where('school_id', $this->user()->school_id),
            ],
            'application_date' => ['nullable', 'date'],
        ];
    }

    protected function group(): Group
    {
        /** @var Group */
        return $this->route('group');
    }
}
