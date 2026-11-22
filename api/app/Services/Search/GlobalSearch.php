<?php

namespace App\Services\Search;

use App\Models\Assessment;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «Buscar en Aula+» (screen 01, P01-DEC-118 / PA-134, ClickUp 1dfd3mc56kr).
 *
 * Each role finds only what it could open (Eitán, ClickUp): the same
 * visibility scopes as the listings decide it — Student::visibleTo,
 * Group::visibleTo and Assessment::visibleTo, on top of the SchoolScope — so
 * no rule is restated here. An assessment also needs its group to be visible,
 * because the result opens that group's assessments.
 *
 * Matching is by name or title only (a student's full name, a group's name,
 * an assessment's purpose — what the Calendar shows as its title), ignoring
 * case and the Spanish accents/diacritics listed in ACCENTS, in SQL that runs
 * the same on SQLite and PostgreSQL (nested REPLACE + LOWER, no extension).
 *
 * Every result carries non-sensitive fields only: never accommodations,
 * barriers, reports or any clinical/learning-profile field. The term may be a
 * student's name, so it is never logged.
 */
class GlobalSearch
{
    /** Maximum results per type. */
    public const LIMIT_PER_TYPE = 8;

    private const TITLE_LENGTH = 120;

    /**
     * Accented letters folded before comparing. Uppercase ones are listed too
     * because SQLite's LOWER() only folds ASCII.
     */
    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];

    /**
     * @return array{students: list<array<string, mixed>>, groups: list<array<string, mixed>>, assessments: list<array<string, mixed>>}
     */
    public function for(User $user, string $term): array
    {
        $pattern = $this->pattern($term);

        return [
            'students' => $this->students($user, $pattern),
            'groups' => $this->groups($user, $pattern),
            'assessments' => $this->assessments($user, $pattern),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function students(User $user, string $pattern): array
    {
        $query = Student::query()->visibleTo($user);

        return $this->matching($query, 'students.full_name', $pattern)
            ->with('groups:groups.id,groups.name')
            ->orderBy('students.full_name')
            ->limit(self::LIMIT_PER_TYPE)
            ->get(['students.id', 'students.full_name'])
            ->map(function (Student $student): array {
                // The group of the student's latest school year.
                $group = $student->groups->sortByDesc(fn (Group $group) => $group->pivot->school_year)->first();

                return [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groups(User $user, string $pattern): array
    {
        $query = Group::query()->visibleTo($user);

        return $this->matching($query, 'groups.name', $pattern)
            ->with('educationLevel:id,label')
            ->orderByDesc('groups.school_year')
            ->orderBy('groups.name')
            ->limit(self::LIMIT_PER_TYPE)
            ->get(['groups.id', 'groups.name', 'groups.education_level_id', 'groups.school_year'])
            ->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'level' => $group->educationLevel?->label,
                'school_year' => $group->school_year,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assessments(User $user, string $pattern): array
    {
        $query = Assessment::query()
            ->visibleTo($user)
            ->whereHas('group', fn (Builder $group) => $group->visibleTo($user));

        return $this->matching($query, 'assessments.purpose', $pattern)
            ->with(['group:id,name', 'subject:id,name'])
            ->orderByDesc('assessments.administered_at')
            ->orderByDesc('assessments.id')
            ->limit(self::LIMIT_PER_TYPE)
            ->get(['assessments.id', 'assessments.purpose', 'assessments.type', 'assessments.group_id', 'assessments.subject_id', 'assessments.administered_at'])
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'title' => Str::limit((string) $assessment->purpose, self::TITLE_LENGTH),
                'type' => $assessment->type->value,
                'subject' => $assessment->subject?->name,
                'group' => ['id' => $assessment->group->id, 'name' => $assessment->group->name],
                'date' => $assessment->administered_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Restrict the query to rows whose (folded) column contains the pattern.
     * $column is always one of this class's constants, never user input.
     */
    private function matching(Builder $query, string $column, string $pattern): Builder
    {
        $expression = DB::connection($query->getModel()->getConnectionName())
            ->getQueryGrammar()
            ->wrap($column);

        foreach (array_keys(self::ACCENTS) as $accented) {
            $expression = "REPLACE({$expression}, '{$accented}', '".self::ACCENTS[$accented]."')";
        }

        return $query->whereRaw("LOWER({$expression}) LIKE ? ESCAPE '!'", [$pattern]);
    }

    /**
     * The folded, LIKE-escaped "contains" pattern for the term.
     */
    private function pattern(string $term): string
    {
        $folded = strtr(mb_strtolower(trim($term)), self::ACCENTS);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $folded);

        return '%'.$escaped.'%';
    }
}
