<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\StudentFileSection;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Read side of the student-file change history (ClickUp 86e3qkwmc).
 *
 * Field-level access (CLAUDE.md rule 11): an entry is only returned when the
 * viewer may read the record it is about, under that record's own rules —
 * Comment::isVisibleTo() for observations (role-restricted or author-only),
 * the model's Policy `view` ability for everything else, and
 * StudentPolicy::viewClinicalProfile for the learning profile / documents /
 * whole-record snapshots. An entry that fails the check is dropped
 * entirely, not returned as a contentless stub: even "someone wrote a
 * psychopedagogy-only observation on this date" is information the original
 * list would not have given that viewer.
 *
 * The visibility check runs in PHP (it reuses the model methods instead of
 * re-encoding them in SQL), so filters and pagination are applied after it,
 * over one student's history — a bounded set.
 */
class StudentChangeHistory
{
    /** @var array<string, bool> */
    private array $decisions = [];

    /**
     * Every entry of `$student`'s history that `$viewer` may see, newest
     * first.
     *
     * @return Collection<int, AuditLog>
     */
    public function visibleEntries(Student $student, User $viewer): Collection
    {
        $rows = AuditLog::query()
            ->where('student_id', $student->id)
            ->whereNotNull('section')
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();

        $subjects = $this->loadSubjects($rows);

        return $rows
            ->filter(fn (AuditLog $row): bool => $this->canView($viewer, $student, $row, $subjects))
            ->values();
    }

    /**
     * @param  Collection<int, AuditLog>  $entries
     * @param  array{user_id?: int|null, section?: string|null, action?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, AuditLog>
     */
    public function filter(Collection $entries, array $filters): Collection
    {
        $from = $this->boundary($filters['from'] ?? null, startOfDay: true);
        $to = $this->boundary($filters['to'] ?? null, startOfDay: false);

        return $entries
            ->when($filters['user_id'] ?? null, fn (Collection $c, $userId) => $c->where('user_id', (int) $userId))
            ->when($filters['section'] ?? null, fn (Collection $c, $section) => $c->filter(fn (AuditLog $e) => $e->section?->value === $section))
            ->when($filters['action'] ?? null, fn (Collection $c, $action) => $c->filter(fn (AuditLog $e) => $e->action->value === $action))
            ->when($from, fn (Collection $c) => $c->filter(fn (AuditLog $e) => $e->created_at->greaterThanOrEqualTo($from)))
            ->when($to, fn (Collection $c) => $c->filter(fn (AuditLog $e) => $e->created_at->lessThanOrEqualTo($to)))
            ->values();
    }

    /**
     * The people who appear in the visible history, for the "Persona" filter.
     *
     * @param  Collection<int, AuditLog>  $entries
     * @return list<array{id: int, name: string, role: string|null}>
     */
    public function actors(Collection $entries): array
    {
        return $entries
            ->filter(fn (AuditLog $e) => $e->user !== null)
            ->groupBy('user_id')
            // Newest first, so the role is the one they last acted with.
            ->map(fn (Collection $own) => [
                'id' => $own->first()->user->id,
                'name' => $own->first()->user->name,
                'role' => $own->first()->actor_role,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * A date-only value (YYYY-MM-DD) covers the whole day in the app timezone;
     * a full ISO-8601 instant (what the SPA sends, built from the viewer's
     * local day) is used as-is.
     */
    private function boundary(?string $value, bool $startOfDay): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $startOfDay ? $date->startOfDay() : $date->endOfDay();
        }

        return $date;
    }

    /**
     * The current record behind each row, one query per model class (soft-
     * deleted ones included). A hard-deleted record is rebuilt from the
     * snapshot its `deleted` row kept, so its visibility rules still apply;
     * a record that cannot be resolved at all is treated as not visible.
     *
     * @param  Collection<int, AuditLog>  $rows
     * @return array<string, array<int, Model>>
     */
    private function loadSubjects(Collection $rows): array
    {
        $subjects = [];

        foreach ($rows->groupBy('auditable_type') as $class => $group) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $query = $class::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withTrashed();
            }

            $subjects[$class] = $query->whereKey($group->pluck('auditable_id')->unique()->all())->get()->keyBy(fn (Model $m) => $m->getKey())->all();

            foreach ($group->where('action', AuditAction::Deleted) as $deleted) {
                if (! isset($subjects[$class][$deleted->auditable_id]) && is_array($deleted->changes)) {
                    $subjects[$class][$deleted->auditable_id] = (new $class)->forceFill($deleted->changes);
                }
            }
        }

        return $subjects;
    }

    /**
     * @param  array<string, array<int, Model>>  $subjects
     */
    private function canView(User $viewer, Student $student, AuditLog $row, array $subjects): bool
    {
        $subject = $subjects[$row->auditable_type][$row->auditable_id] ?? null;

        if ($subject === null) {
            return false;
        }

        if ($subject instanceof Student) {
            $ability = $row->action === AuditAction::Updated
                && in_array($row->section, [StudentFileSection::StudentData, StudentFileSection::Summary], true)
                ? 'view'
                : 'view-clinical-profile';

            return $this->decide("student:{$ability}", fn () => Gate::forUser($viewer)->allows($ability, $student));
        }

        $key = $row->auditable_type.':'.$row->auditable_id;

        if ($subject instanceof Comment) {
            return $this->decide($key, fn () => $subject->isVisibleTo($viewer));
        }

        return $this->decide($key, fn () => Gate::forUser($viewer)->allows('view', $subject));
    }

    private function decide(string $key, callable $check): bool
    {
        return $this->decisions[$key] ??= (bool) $check();
    }
}
