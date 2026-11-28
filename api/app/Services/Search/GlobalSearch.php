<?php

namespace App\Services\Search;

use App\Enums\AssessmentType;
use App\Models\Assessment;
use App\Models\ClassSession;
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
 * Group::visibleTo, Assessment::visibleTo and ClassSession::visibleTo (the
 * mirror of ClassSessionPolicy::view, which guards the class's own screen),
 * on top of the SchoolScope — so no rule is restated here. An assessment also
 * needs its group to be visible, because the result opens that group's
 * assessments.
 *
 * Matching is by name or title only (a student's full name, a group's name,
 * an assessment's purpose — what the Calendar shows as its title —, a class's
 * title). An assessment without a purpose has no title, so the SPA shows it
 * with a composed name «type · subject · group · date» (e.g. «Escrita ·
 * Matemática · 5.°A · 12/11», Eitán 26/11) and it is found by any of those
 * words: every word of the term must match its type, subject, group or date
 * (see untitledAssessmentMatches). Matching ignores
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
     * Spanish words an untitled assessment's type is found by. The SPA's
     * display labels are `assessmentTypeLabels` (web/src/types.ts); these
     * are search keywords only (folded, so without accents), which is why
     * they live server-side, where the matching runs. «escrito» is listed
     * too because that is how Eitán wrote it (ClickUp, 26/11).
     */
    private const TYPE_KEYWORDS = [
        'written' => ['escrita', 'escrito'],
        'assignment' => ['tarea'],
        'project' => ['proyecto'],
        'oral' => ['oral'],
        'submission' => ['entrega'],
    ];

    /**
     * Accented letters folded before comparing. Uppercase ones are listed too
     * because SQLite's LOWER() only folds ASCII.
     */
    private const ACCENTS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];

    /**
     * @return array{students: list<array<string, mixed>>, groups: list<array<string, mixed>>, assessments: list<array<string, mixed>>, classes: list<array<string, mixed>>}
     */
    public function for(User $user, string $term): array
    {
        $pattern = $this->pattern($term);

        return [
            'students' => $this->students($user, $pattern),
            'groups' => $this->groups($user, $pattern),
            'assessments' => $this->assessments($user, $pattern, $term),
            'classes' => $this->classes($user, $pattern),
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
    private function assessments(User $user, string $pattern, string $term): array
    {
        $query = Assessment::query()
            ->visibleTo($user)
            ->whereHas('group', fn (Builder $group) => $group->visibleTo($user));

        $query->where(function (Builder $where) use ($pattern, $term): void {
            // Titled ones: by their title, as before (Eitán 26/11).
            $this->matching($where, 'assessments.purpose', $pattern)
                ->whereRaw("TRIM(assessments.purpose) <> ''");
            // Untitled ones: by the words of their composed name.
            $where->orWhere(function (Builder $untitled) use ($term): void {
                $untitled->where(fn (Builder $empty) => $empty
                    ->whereNull('assessments.purpose')
                    ->orWhereRaw("TRIM(assessments.purpose) = ''"));
                $this->untitledAssessmentMatches($untitled, $term);
            });
        });

        return $query
            ->with(['group:id,name', 'subject:id,name'])
            ->orderByDesc('assessments.administered_at')
            ->orderByDesc('assessments.id')
            ->limit(self::LIMIT_PER_TYPE)
            ->get(['assessments.id', 'assessments.purpose', 'assessments.type', 'assessments.group_id', 'assessments.subject_id', 'assessments.administered_at'])
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                // null when it has no purpose: the SPA composes its name.
                'title' => trim((string) $assessment->purpose) === ''
                    ? null
                    : Str::limit((string) $assessment->purpose, self::TITLE_LENGTH),
                'type' => $assessment->type->value,
                'subject' => $assessment->subject?->name,
                'group' => ['id' => $assessment->group->id, 'name' => $assessment->group->name],
                'date' => $assessment->administered_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Classes (screens 14–15): title, subject, group and date only — never
     * their objective, description, sections or the teacher's notes.
     *
     * @return list<array<string, mixed>>
     */
    private function classes(User $user, string $pattern): array
    {
        $query = ClassSession::query()->visibleTo($user);

        return $this->matching($query, 'class_sessions.title', $pattern)
            ->with(['group:id,name', 'subject:id,name'])
            ->orderByDesc('class_sessions.date')
            ->orderByDesc('class_sessions.id')
            ->limit(self::LIMIT_PER_TYPE)
            ->get(['class_sessions.id', 'class_sessions.title', 'class_sessions.group_id', 'class_sessions.subject_id', 'class_sessions.date'])
            ->map(fn (ClassSession $class): array => [
                'id' => $class->id,
                'title' => Str::limit($class->title, self::TITLE_LENGTH),
                'subject' => $class->subject?->name,
                'group' => $class->group ? ['id' => $class->group->id, 'name' => $class->group->name] : null,
                'date' => $class->date->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every word of the term must match the untitled assessment's type
     * (TYPE_KEYWORDS), subject name, group name or date («12/11» or
     * «12/11/2026», as day/month[/year]). «·» separators are ignored.
     */
    private function untitledAssessmentMatches(Builder $query, string $term): void
    {
        $words = preg_split('/[\s·]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            $pattern = $this->pattern($word);
            $folded = strtr(mb_strtolower($word), self::ACCENTS);
            $types = collect(self::TYPE_KEYWORDS)
                ->filter(fn (array $keywords) => collect($keywords)->contains(fn (string $keyword) => str_contains($keyword, $folded)))
                ->keys()
                ->map(fn (string $type) => AssessmentType::from($type)->value)
                ->all();
            $date = $this->dayMonth($word);

            $query->where(function (Builder $any) use ($pattern, $types, $date): void {
                $any->whereHas('subject', fn (Builder $subject) => $this->matching($subject, 'subjects.name', $pattern))
                    ->orWhereHas('group', fn (Builder $group) => $this->matching($group, 'groups.name', $pattern));
                if ($types !== []) {
                    $any->orWhereIn('assessments.type', $types);
                }
                if ($date !== null) {
                    $any->orWhere(function (Builder $on) use ($date): void {
                        $on->whereDay('assessments.administered_at', $date['day'])
                            ->whereMonth('assessments.administered_at', $date['month']);
                        if ($date['year'] !== null) {
                            $on->whereYear('assessments.administered_at', $date['year']);
                        }
                    });
                }
            });
        }
    }

    /**
     * «12/11» or «12/11/26» / «12/11/2026» as day, month and optional year.
     *
     * @return array{day: int, month: int, year: int|null}|null
     */
    private function dayMonth(string $word): ?array
    {
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2}|\d{4}))?$/', $word, $m)) {
            return null;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
            return null;
        }
        $year = isset($m[3]) ? (int) $m[3] : null;
        if ($year !== null && $year < 100) {
            $year += 2000;
        }

        return ['day' => $day, 'month' => $month, 'year' => $year];
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
