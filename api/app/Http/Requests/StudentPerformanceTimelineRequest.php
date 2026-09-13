<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query params for GET /api/v1/students/{student}/performance-timeline
 * (docs/prompts/20-linea-tiempo-alumno.md §1). Read-only aggregator: the only
 * inputs are the optional `from`/`to` date window applied to all six sources.
 *
 * Authorization mirrors GET /students/{student}/tracking (Session 4):
 * StudentPolicy::view. Tenant isolation is enforced first by the SchoolScope
 * on route-model binding (a student from another school is never resolved →
 * 404), then the role rule here (a teacher only passes for a student they
 * teach → 403 otherwise). The narrower clinical gating (which *mark types*
 * are returned) is applied per-source in PerformanceTimelineBuilder, not here.
 */
class StudentPerformanceTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        return $this->user()->can('view', $student);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
