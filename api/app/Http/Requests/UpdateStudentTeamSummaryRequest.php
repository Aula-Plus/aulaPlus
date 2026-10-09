<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Psychopedagogy writes the team-facing summary of a student's technical report
 * (strengths / what is hard in class terms / adjustments). Authorization reuses
 * StudentPolicy::editTeamSummary. The text is sensitive (minor's learning
 * profile, CLAUDE.md rule 11) so it is excluded from audit diffs on the model.
 */
class UpdateStudentTeamSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('editTeamSummary', $this->route('student'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'strengths' => ['required', 'string', 'max:2000'],
            'difficulties' => ['required', 'string', 'max:2000'],
            'adjustments' => ['required', 'string', 'max:2000'],
        ];
    }
}
