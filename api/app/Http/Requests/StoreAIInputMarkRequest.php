<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAIInputComment;
use App\Models\AIInputMark;
use App\Models\Group;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /groups/{group}/ai-input/marks — «La IA lo entendió mal» on one line
 * (psychopedagogy only). `line_key` names the line; whether it is still in
 * the input is checked when the mark is made (AIInputMarks::mark()).
 */
class StoreAIInputMarkRequest extends FormRequest
{
    use ValidatesAIInputComment;

    public function authorize(): bool
    {
        return $this->user()->can('create', [AIInputMark::class, $this->group()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'line_key' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'subject_id' => [
                'nullable', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', Tenancy::schoolId())->whereNull('deleted_at'),
            ],
            ...$this->commentRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectStudentNamesInComment($validator, $this->group())];
    }

    public function group(): Group
    {
        /** @var Group */
        return $this->route('group');
    }
}
