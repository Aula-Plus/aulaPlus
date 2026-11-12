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
use Illuminate\Validation\Validator;

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
 *
 * PO answers of 12/11:
 * - With two valid scales and none chosen yet, nothing can be recorded until
 *   the teacher chooses one (PUT /assessments/{id}/result-scale).
 * - A result already recorded the other way (a score where a level is now
 *   recorded, or the reverse) is kept and stays visible: replacing it needs
 *   an explicit `replace_previous: true` on that row, or it is a 422.
 * - `numeric_grade` is only written when the teacher may write it AND the row
 *   carries it: turning the switch off or configuring a school equivalence
 *   never erases the number the teacher wrote.
 */
class StoreAssessmentResultsRequest extends FormRequest
{
    /** @var Collection<int, int>|null */
    protected ?Collection $enrolledStudentIds = null;

    protected ?OfficialScale $scale = null;

    protected bool $scaleResolved = false;

    /** @var Collection<int, OfficialScale> */
    protected Collection $choices;

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
            'results.*.student_id' => ['required', 'integer', 'distinct', $this->studentBelongsToGroup()],
            'results.*.feedback' => ['nullable', 'string'],
            'results.*.replace_previous' => ['sometimes', 'boolean'],
        ];

        $scale = $this->scale();

        if ($scale === null) {
            return $rules + [
                'results.*.score' => ['required', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
                'results.*.scale_level_id' => ['prohibited'],
                'results.*.numeric_grade' => ['prohibited'],
            ];
        }

        return $rules + [
            'results.*.score' => ['prohibited'],
            'results.*.scale_level_id' => ['required', 'integer', Rule::in($scale->levels->pluck('id')->all())],
            'results.*.numeric_grade' => $this->teacherWritesNumber()
                ? ['nullable', 'integer', 'min:1', 'max:10']
                : ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->scale() === null && $this->choices()->isNotEmpty()) {
                    $validator->errors()->add('results', 'Choose the scale this assessment is recorded with first.');

                    return;
                }

                $this->rejectSilentReplacements($validator);
            },
        ];
    }

    /**
     * Whether the teacher writes the number next to the level for this
     * assessment (the school shows it and has no equivalence of its own).
     */
    public function teacherWritesNumber(): bool
    {
        $scale = $this->scale();

        if ($scale === null) {
            return false;
        }

        $resultScales = app(ResultScales::class);
        $school = School::query()->findOrFail($this->assessment()->school_id);

        return $resultScales->teacherWritesNumber($school, $scale, $resultScales->equivalences($school));
    }

    /**
     * The official scale the assessment records with (null = decimal score, or
     * a choice still pending), resolved once per request.
     */
    public function scale(): ?OfficialScale
    {
        $this->resolveScale();

        return $this->scale;
    }

    /**
     * @return Collection<int, OfficialScale>
     */
    protected function choices(): Collection
    {
        $this->resolveScale();

        return $this->choices;
    }

    protected function resolveScale(): void
    {
        if ($this->scaleResolved) {
            return;
        }

        $this->scaleResolved = true;
        ['scale' => $this->scale, 'choices' => $this->choices] = app(ResultScales::class)
            ->resolveForAssessment($this->assessment());
    }

    /**
     * A row that would overwrite a result recorded the other way needs
     * `replace_previous: true`.
     */
    protected function rejectSilentReplacements(Validator $validator): void
    {
        $rows = collect($this->input('results', []));
        $existing = $this->assessment()->results()
            ->whereIn('student_id', $rows->pluck('student_id')->map(intval(...)))
            ->get()
            ->keyBy('student_id');
        $byScale = $this->scale() !== null;

        foreach ($rows as $index => $row) {
            $previous = $existing->get((int) $row['student_id']);

            if ($previous === null || filter_var($row['replace_previous'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            if ($byScale && $previous->score !== null) {
                $validator->errors()->add(
                    "results.{$index}.scale_level_id",
                    'This student already has a result recorded as a score; replacing it must be explicit.',
                );
            } elseif (! $byScale && $previous->official_scale_level_id !== null) {
                $validator->errors()->add(
                    "results.{$index}.score",
                    'This student already has a result recorded as a scale level; replacing it must be explicit.',
                );
            }
        }
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
