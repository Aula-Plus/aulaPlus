<?php

namespace App\Http\Requests;

use App\Models\AIInputMark;
use App\Models\Group;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /groups/{group}/ai-input — the subject is optional (P03-AJU-03: "grupo
 * y materia opcional").
 */
class AIInputReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Group $group */
        $group = $this->route('group');

        return $this->user()->can('viewAny', [AIInputMark::class, $group]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject_id' => [
                'nullable', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId())->whereNull('deleted_at'),
            ],
        ];
    }
}
