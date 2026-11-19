<?php

namespace App\Http\Requests;

use App\Enums\AIProposalType;
use App\Enums\ClassSection;
use App\Enums\ClassSectionDecision;
use App\Models\AIProposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Validates POST /ai-proposals/{ai_proposal}/apply.
 *
 * Only the requester may apply (AIProposalPolicy::apply). A class draft made
 * of sections (screen 15, ClickUp 86e3jujh0) is confirmed with the teacher's
 * decision on every section: approved, edited (with the teacher's text) or
 * rejected. Nothing the teacher did not approve is saved (P15-DEC-01), and a
 * class can't be confirmed with every section rejected. Other drafts take no
 * body.
 */
class ApplyAIProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('apply', $this->proposal());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if (! $this->hasSections()) {
            return [];
        }

        $rules = [
            'title' => ['nullable', 'string', 'max:255'],
            'sections' => ['required', 'array'],
        ];

        foreach (ClassSection::values() as $key) {
            $rules["sections.{$key}"] = ['required', 'array'];
            $rules["sections.{$key}.decision"] = ['required', new Enum(ClassSectionDecision::class)];
            $rules["sections.{$key}.content"] = [
                'nullable',
                'required_if:sections.'.$key.'.decision,'.ClassSectionDecision::Edited->value,
                'string',
                'max:5000',
            ];
            $rules["sections.{$key}.duration_minutes"] = ['nullable', 'integer', 'min:0', 'max:300'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasSections() || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $kept = collect(ClassSection::values())
                    ->reject(fn (string $key) => $this->input("sections.{$key}.decision") === ClassSectionDecision::Rejected->value);

                if ($kept->isEmpty()) {
                    $validator->errors()->add('sections', 'A class needs at least one approved section.');
                }
            },
        ];
    }

    /**
     * A class draft in sections. Older class drafts (one description) are
     * applied as before, without a body.
     */
    public function hasSections(): bool
    {
        $proposal = $this->proposal();

        return $proposal->type === AIProposalType::ClassSession
            && is_array($proposal->raw_response['sections'] ?? null);
    }

    public function proposal(): AIProposal
    {
        /** @var AIProposal */
        return $this->route('ai_proposal');
    }
}
