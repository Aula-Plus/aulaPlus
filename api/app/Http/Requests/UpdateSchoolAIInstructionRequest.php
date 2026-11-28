<?php

namespace App\Http\Requests;

use App\Models\SchoolAIInstruction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Direction edits the fields of one rule (screen 24, ClickUp 1dfd3mc4bh6).
 * The screen saves on its own as Direction writes (P24-DEC-07: «Se guarda
 * solo»); the rule's scope does not change here — a different scope is
 * another rule. Route-model binding is tenant-scoped (BelongsToSchool), and
 * the role is checked again here.
 */
class UpdateSchoolAIInstructionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var SchoolAIInstruction $rule */
        $rule = $this->route('instruction');

        return $this->user()->can('manageAIInstructions', $rule->school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var SchoolAIInstruction $rule */
        $rule = $this->route('instruction');

        return [
            ...SchoolAIInstructionSettingsRules::for($rule->request_type->value),
            'settings' => ['required', 'array'],
        ];
    }
}
