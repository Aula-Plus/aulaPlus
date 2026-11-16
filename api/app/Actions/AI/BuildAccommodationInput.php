<?php

namespace App\Actions\AI;

use App\Models\Accommodation;
use App\Models\AIInputMark;
use App\Models\Group;
use App\Models\Student;
use App\Support\StudentNameMatcher;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
     * The lines the AI receives for the group, without the ones a pending
     * «La IA lo entendió mal» mark withholds (ClickUp 1dfd3mc2wx2,
     * P03-AJU-03: "la línea marcada deja de enviarse mientras esté
     * pendiente").
     *
     * @param  Closure(HasMany<Accommodation, Student>): void  $inScope
     * @return list<array{action: string, category: string|null, subjects: list<string>|string, assessment_types: list<string>|string}>
     */
    public function __invoke(Group $group, Closure $inScope): array
    {
        $withheld = array_flip(self::withheldKeys($group));

        return $this->lines($group, $inScope)
            ->reject(fn (array $entry): bool => isset($withheld[$entry['key']]))
            ->map(fn (array $entry): array => $entry['line'])
            ->values()
            ->all();
    }

    /**
     * Every line the scope yields, with its stable key and the accommodations
     * it comes from (each with its student loaded) — what the review view of
     * psychopedagogy and direction needs («Ver ajuste de origen»). The
     * origins never leave the application: only `line` is sent to the AI.
     *
     * @param  Closure(HasMany<Accommodation, Student>): void  $inScope
     * @return Collection<int, array{key: string, line: array{action: string, category: string|null, subjects: list<string>|string, assessment_types: list<string>|string}, origins: Collection<int, Accommodation>}>
     */
    public function lines(Group $group, Closure $inScope): Collection
    {
        $students = $group->students()
            ->with(['accommodations' => function (HasMany $query) use ($inScope): void {
                $inScope($query);
                $query->with('subjects');
            }])
            ->get();

        $names = StudentNameMatcher::forGroup($group);

        return $students
            ->flatMap(fn (Student $student) => $student->accommodations
                ->each(fn (Accommodation $accommodation) => $accommodation->setRelation('student', $student)))
            // `type` is free text typed by staff: a line that names a student
            // never leaves the database.
            ->reject(fn (Accommodation $accommodation): bool => trim((string) $accommodation->type) === ''
                || $names->containsAnyName((string) $accommodation->type))
            ->map(fn (Accommodation $accommodation): array => [
                'line' => [
                    'action' => trim((string) $accommodation->type),
                    'category' => $accommodation->category?->value,
                    'subjects' => $accommodation->subjects->isEmpty()
                        ? self::ALL
                        : $accommodation->subjects->pluck('name')->sort()->values()->all(),
                    'assessment_types' => empty($accommodation->assessment_types)
                        ? self::ALL
                        : collect($accommodation->assessment_types)->sort()->values()->all(),
                ],
                'origin' => $accommodation,
            ])
            // Same action typed with other casing or spacing is one line: a
            // repeated action would hint how many students have it.
            ->groupBy(fn (array $entry): string => self::lineKey($entry['line']))
            ->map(fn (Collection $entries, string $key): array => [
                'key' => $key,
                'line' => $entries->first()['line'],
                'origins' => $entries->pluck('origin')->values(),
            ])
            ->sortBy(fn (array $entry): string => mb_strtolower($entry['line']['action']))
            ->values();
    }

    /**
     * Stable identifier of a line: the same action and scope give the same
     * key whatever the casing or spacing of the action. Any change to what
     * the AI would read (action, category, subjects, assessment types) gives
     * another key — that is how a mark knows its accommodation changed.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineKey(array $line): string
    {
        return hash('sha256', (string) json_encode([
            ...$line,
            'action' => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $line['action']))),
        ]));
    }

    /**
     * Keys of the group's lines with a pending mark.
     *
     * @return list<string>
     */
    public static function withheldKeys(Group $group): array
    {
        return AIInputMark::query()
            ->where('group_id', $group->id)
            ->pending()
            ->pluck('line_key')
            ->unique()
            ->values()
            ->all();
    }
}
