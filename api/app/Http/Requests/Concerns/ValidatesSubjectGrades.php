<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AssessmentType;
use App\Enums\IndicatorSourceType;
use App\Enums\SubjectGradeRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The grade rule of a subject datum (SubjectGradeRule, PO answer of 17/11 on
 * ClickUp 1dfd3mc4bgp), shared by indicator inputs and crossing conditions:
 * `grades: {rule, category}`. Only a subject takes it; a rule over one
 * category needs the category (an assessment type) and the others take
 * none. Leaving it out reads every grade.
 */
trait ValidatesSubjectGrades
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function subjectGradesRules(string $prefix): array
    {
        return [
            "{$prefix}.grades" => ['sometimes', 'nullable', 'array:rule,category'],
            "{$prefix}.grades.rule" => ['sometimes', 'string', Rule::enum(SubjectGradeRule::class)],
            "{$prefix}.grades.category" => ['sometimes', 'nullable', 'string', Rule::enum(AssessmentType::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $source  `{type, id, grades?}`
     */
    protected function validateSubjectGrades(Validator $validator, string $path, array $source): void
    {
        $grades = $source['grades'] ?? null;

        if ($grades === null) {
            return;
        }

        if (($source['type'] ?? null) !== IndicatorSourceType::Subject->value) {
            $validator->errors()->add("{$path}.grades", 'Only a subject datum takes a grade rule.');

            return;
        }

        $rule = SubjectGradeRule::tryFrom((string) ($grades['rule'] ?? ''));

        if ($rule === null) {
            $validator->errors()->add("{$path}.grades.rule", 'The grade rule is required.');

            return;
        }

        $category = $grades['category'] ?? null;

        if ($rule->needsCategory() && ($category === null || $category === '')) {
            $validator->errors()->add("{$path}.grades.category", 'This grade rule needs a category.');
        } elseif (! $rule->needsCategory() && $category !== null) {
            $validator->errors()->add("{$path}.grades.category", 'This grade rule takes no category.');
        }
    }
}
