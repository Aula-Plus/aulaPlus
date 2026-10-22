<?php

namespace App\Http\Requests;

use App\Models\School;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /schools/{school}/adoption-dashboard/teachers/{teacher}/journeys
 * (ClickUp 86e3qkxtc). Dirección of that school only — the same rule as the
 * rest of the adoption dashboard (SchoolPolicy::viewAdoptionDashboard).
 */
class AdoptionTeacherJourneysRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var School $school */
        $school = $this->route('school');

        return $this->user()->can('view-adoption-dashboard', $school);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // The month to list (YYYY-MM); defaults to the current one.
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }
}
