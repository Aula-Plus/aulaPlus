<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\OfficialScale;
use App\Models\School;
use App\Services\ResultScales;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Upserting a batch of results for one assessment (docs/prompts/13-
 * evaluaciones-resultados.md §2). Only the teacher who owns the parent
 * assessment may write results, enforced via AssessmentResultPolicy::create
 * (which mirrors AssessmentPolicy::update).
 *
 * Each student_id must belong to the assessment's group. A student has no
 * direct group_id — membership lives in the historized group_student pivot —
 * so we check it through the relation, exactly as the spec prescribes.
 *
 * How each row is recorded depends on the group (ClickUp 1dfd3mc4bgg,
 * App\Services\ResultScales):
 *
 * - A Primaria group with an official scale: `scale_level_id` is required and
 *   must be a level of THAT scale; `score` is prohibited. `numeric_grade`
 *   (whole number 1..10, optional) is accepted only when the school shows the
 *   number and has no equivalence of its own — otherwise it is prohibited.
 * - Any other group: the decimal `score` as before; level and number are
 *   prohibited.
 */
class StoreAssessmentResultsRequest extends FormRequest
{
    /** @var Collection<int, int>|null */
    protected ?Collection $enrolledStudentIds = null;

    protected ?OfficialScale $scale = null;

    protected bool $scaleResolved = false;

    public function authorize(): bool
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $this->user()->can('create', [AssessmentResult::class, $assessment]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'results' => ['required', 'array', 'min:1'],
            'results.*.student_id' => ['required', 'integer', $this->studentBelongsToGroup()],
            'results.*.feedback' => ['nullable', 'string'],
        ];

        $scale = $this->scale();

        if ($scale === null) {
            return $rules + [
                'results.*.score' => ['required', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
                'results.*.scale_level_id' => ['prohibited'],
                'results.*.numeric_grade' => ['prohibited'],
            ];
        }

        $resultScales = app(ResultScales::class);
        $school = School::query()->findOrFail($this->assessment()->school_id);
        $teacherWritesNumber = $resultScales->teacherWritesNumber(
            $school,
            $scale,
            $resultScales->equivalences($school),
        );

        return $rules + [
            'results.*.score' => ['prohibited'],
            'results.*.scale_level_id' => ['required', 'integer', Rule::in($scale->levels->pluck('id')->all())],
            'results.*.numeric_grade' => $teacherWritesNumber
                ? ['nullable', 'integer', 'min:1', 'max:10']
                : ['prohibited'],
        ];
    }

    /**
     * The official scale the assessment's group records with (null = decimal
     * score), resolved once per request.
     */
    public function scale(): ?OfficialScale
    {
        if (! $this->scaleResolved) {
            $this->scaleResolved = true;
            $group = $this->assessment()->group;
            $this->scale = $group ? app(ResultScales::class)->scaleForGroup($group) : null;
        }

        return $this->scale;
    }

    protected function assessment(): Assessment
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment;
    }

    /**
     * Reject (422) any student_id that isn't enrolled in the assessment's group.
     *
     * The set of enrolled student ids is loaded once (not once per row) so a
     * full-class batch stays a single membership query instead of an N+1.
     */
    protected function studentBelongsToGroup(): Closure
    {
        $enrolledIds = $this->enrolledStudentIds();

        return function (string $attribute, mixed $value, Closure $fail) use ($enrolledIds): void {
            if (! $enrolledIds->contains((int) $value)) {
                $fail('The selected student does not belong to the assessment group.');
            }
        };
    }

    /**
     * Ids of the students enrolled in the assessment's group, resolved once and
     * memoized for the lifetime of the request.
     *
     * @return Collection<int, int>
     */
    protected function enrolledStudentIds(): Collection
    {
        return $this->enrolledStudentIds ??= (function (): Collection {
            /** @var Assessment $assessment */
            $assessment = $this->route('assessment');

            return $assessment->group
                ? $assessment->group->students()->pluck('students.id')->map(intval(...))
                : collect();
        })();
    }
}
