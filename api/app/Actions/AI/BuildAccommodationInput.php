<?php

namespace App\Actions\AI;

use App\Models\Accommodation;
use App\Models\Group;
use App\Models\Student;
use App\Support\StudentNameMatcher;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Lo que recibe la IA» (ClickUp 86e3jpzdr, PO decision of 24/10): the
 * internal input the AI gets about a group's accommodations when it drafts a
 * proposal. It is built on the fly from the accommodations psychopedagogy
 * confirmed — there is no step where someone writes or publishes it — and it
 * is NOT the group/student summary of strengths and weaknesses a person reads
 * (hence the different name).
 *
 * One line per distinct pedagogical action, with the subjects (and assessment
 * types) it applies to. Never a diagnosis, a student's name, which student has
 * it or how many do (in a small group that identifies someone — CLAUDE.md
 * security rule 11): accommodations of several students with the same action
 * and scope collapse into a single line, and `description`/`focus_area` (free
 * text that may carry clinical detail) are never read.
 *
 * Which accommodations: the caller's scope, on top of effective + started
 * ({@see BuildProposalContext::accommodationsInScope()}). Effective already
 * excludes direction proposals psychopedagogy has not confirmed (pending,
 * returned, rejected, withdrawn) — {@see Accommodation::scopeConfirmed()}.
 */
class BuildAccommodationInput
{
    /** Marker for "every subject" / "every assessment type". */
    public const ALL = 'all';

    /**
     * @param  Closure(HasMany<Accommodation, Student>): void  $inScope
     * @return list<array{action: string, category: string|null, subjects: list<string>|string, assessment_types: list<string>|string}>
     */
    public function __invoke(Group $group, Closure $inScope): array
    {
        $students = $group->students()
            ->with(['accommodations' => function (HasMany $query) use ($inScope): void {
                $inScope($query);
                $query->with('subjects');
            }])
            ->get();

        $names = StudentNameMatcher::forGroup($group);

        return $students
            ->flatMap(fn (Student $student) => $student->accommodations)
            // `type` is free text typed by staff: a line that names a student
            // never leaves the database.
            ->reject(fn (Accommodation $accommodation): bool => trim((string) $accommodation->type) === ''
                || $names->containsAnyName((string) $accommodation->type))
            ->map(fn (Accommodation $accommodation): array => [
                'action' => trim((string) $accommodation->type),
                'category' => $accommodation->category?->value,
                'subjects' => $accommodation->subjects->isEmpty()
                    ? self::ALL
                    : $accommodation->subjects->pluck('name')->sort()->values()->all(),
                'assessment_types' => empty($accommodation->assessment_types)
                    ? self::ALL
                    : collect($accommodation->assessment_types)->sort()->values()->all(),
            ])
            ->unique(fn (array $line): string => (string) json_encode($line))
            ->sortBy(fn (array $line): string => mb_strtolower($line['action']))
            ->values()
            ->all();
    }
}
