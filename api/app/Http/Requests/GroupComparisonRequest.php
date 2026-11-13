<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/group-comparison (screen 05): which «año de cursada» to show.
 * Authorized with GroupPolicy::compare (Psicopedagogía and Dirección only).
 * `school_grades` is a global catalogue, so `exists` needs no school filter;
 * the groups themselves resolve through the SchoolScope.
 */
class GroupComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('compare', Group::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'school_grade_id' => ['required', 'integer', 'exists:school_grades,id'],
            'school_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
