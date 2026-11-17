<?php

namespace App\Services\Indicators;

use App\Enums\AssessmentType;
use App\Enums\IndicatorSourceType;
use App\Enums\SubjectGradeRule;

/**
 * The grade rule of a subject datum (SubjectGradeRule plus, when the rule
 * needs one, the category): stored as `grades: {rule, category}` next to the
 * `{type, id}` of an indicator input or a crossing condition. A subject
 * reference without it reads every grade (`all`), as before the rule
 * existed.
 */
final class SubjectGrades
{
    public function __construct(
        public readonly SubjectGradeRule $rule = SubjectGradeRule::All,
        public readonly ?AssessmentType $category = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $grades
     */
    public static function fromArray(?array $grades): self
    {
        $rule = SubjectGradeRule::tryFrom((string) ($grades['rule'] ?? '')) ?? SubjectGradeRule::All;
        $category = $rule->needsCategory() ? AssessmentType::tryFrom((string) ($grades['category'] ?? '')) : null;

        // A rule that needs a category but lost it reads every grade.
        return $rule->needsCategory() && $category === null ? new self : new self($rule, $category);
    }

    /**
     * The `grades` to store with a reference: only a subject has one.
     *
     * @param  array<string, mixed>|null  $grades
     * @return array{rule: string, category: string|null}|null
     */
    public static function forSource(string $type, ?array $grades): ?array
    {
        return $type === IndicatorSourceType::Subject->value ? self::fromArray($grades)->toArray() : null;
    }

    /**
     * @return array{rule: string, category: string|null}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule->value, 'category' => $this->category?->value];
    }

    /** Memo key: two rules over the same subject are two different data. */
    public function key(): string
    {
        return $this->rule->value.($this->category ? ':'.$this->category->value : '');
    }
}
