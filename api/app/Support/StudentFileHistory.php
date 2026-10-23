<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\AuditOrigin;
use App\Enums\StudentFileSection;
use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Barrier;
use App\Models\Comment;
use App\Models\Concerns\Auditable;
use App\Models\ScheduledFollowUp;
use App\Models\Student;
use App\Models\TechnicalReport;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Write side of the student-file change history (ClickUp 86e3qkwmc): the
 * single registry of which models belong to a student's file ("ficha"),
 * which section each of their fields falls under, and which fields are worth
 * showing to a person reading the history.
 *
 * It does not add a second trail: {@see Auditable} still writes every
 * created/updated/deleted row; for a model registered here it hands the row
 * over to {@see self::recordModelChange()}, which annotates it with
 * `student_id`, `section`, `actor_role` and the readable `details` (encrypted
 * at rest). Pivot-only changes that fire no model event (group membership,
 * barrier↔accommodation links, an observation's categories) are recorded with {@see self::recordManual()}
 * from the controllers that make them.
 *
 * Only changes are recorded — reads (opening the file, switching tabs,
 * looking at a document) never reach this class.
 *
 * Sensitive content (CLAUDE.md rule 11): `details` may hold it, but it is
 * never written to application logs, and the read side
 * (App\Services\StudentChangeHistory) only returns an entry when the viewer
 * may read the original record. Fields in {@see self::REDACTED} are recorded
 * as "changed" without their value even there.
 */
class StudentFileHistory
{
    /**
     * Fields that are listed as changed but whose value is never copied into
     * the history: an internal AI rule and a private storage path (access to
     * report files goes through signed URLs only — CLAUDE.md rule 7).
     */
    public const REDACTED = ['llm_rule', 'document_url'];

    private const LABEL_LENGTH = 120;

    /**
     * Displayable fields per model, each mapped to the section it belongs to.
     * Anything not listed (ids, foreign keys, timestamps, school_id…) is
     * internal and never shown.
     *
     * @return array<string, StudentFileSection>
     */
    public static function fieldsFor(string $class): array
    {
        $all = fn (StudentFileSection $section, array $fields): array => array_fill_keys($fields, $section);

        return match ($class) {
            Student::class => [
                ...$all(StudentFileSection::StudentData, ['full_name', 'photo_url', 'birth_date', 'enrollment_year', 'has_therapeutic_companion', 'group']),
                ...$all(StudentFileSection::LearningProfile, ['learning_profile', 'individual_profile', 'tracking_notes']),
                ...$all(StudentFileSection::Documents, ['related_documents']),
                ...$all(StudentFileSection::Summary, ['team_summary_strengths', 'team_summary_difficulties', 'team_summary_adjustments', 'team_summary_confirmed_at']),
            ],
            Accommodation::class => $all(StudentFileSection::Accommodations, ['type', 'category', 'description', 'focus_area', 'active', 'requires_external_approval', 'approved', 'llm_rule']),
            AccommodationInstanceOverride::class => $all(StudentFileSection::Accommodations, ['assessment_id', 'reason']),
            Barrier::class => $all(StudentFileSection::Barriers, ['description', 'coping_strategy', 'active', 'accommodation', 'validated']),
            // `categories` is a pivot, not a column: see categoryNamesOf().
            Comment::class => $all(StudentFileSection::Observations, ['content', 'visible_to', 'author_only', 'categories']),
            TechnicalReport::class => $all(StudentFileSection::Reports, ['summary', 'attachments', 'document_url']),
            ScheduledFollowUp::class => $all(StudentFileSection::FollowUps, ['description', 'due_date', 'resolved', 'resolution_note']),
            Alert::class => $all(StudentFileSection::Alerts, ['type', 'severity', 'description', 'outcome', 'due_on', 'resolved']),
            default => [],
        };
    }

    /**
     * The section a created/deleted row of this model is filed under (an
     * update is split per field instead — see recordModelChange()).
     */
    public static function defaultSectionFor(string $class): ?StudentFileSection
    {
        $fields = self::fieldsFor($class);

        return $fields === [] ? null : reset($fields);
    }

    /**
     * Snake-case entity identifier used by the frontend to name the element.
     */
    public static function entityFor(string $class): string
    {
        return Str::snake(class_basename($class));
    }

    /**
     * The student whose file `$model` belongs to, or null when it is not part
     * of one (e.g. a comment on a group).
     */
    public static function studentIdFor(Model $model): ?int
    {
        return match (true) {
            $model instanceof Student => $model->getKey(),
            $model instanceof Comment => $model->commentable_type === Student::class ? (int) $model->commentable_id : null,
            $model instanceof AccommodationInstanceOverride => Accommodation::withTrashed()->whereKey($model->accommodation_id)->value('student_id'),
            $model instanceof Accommodation,
            $model instanceof Barrier,
            $model instanceof TechnicalReport,
            $model instanceof ScheduledFollowUp,
            $model instanceof Alert => $model->getAttribute('student_id'),
            default => null,
        };
    }

    /**
     * Write the audit row(s) for a model change. Returns false when the model
     * is not part of a student's file, in which case the caller writes its
     * plain row as before.
     *
     * An update touching fields of several sections (e.g. the student form
     * saving basic data and the learning profile at once) becomes one row
     * per section, so filtering by section never hides part of a change.
     *
     * @param  array<string, mixed>  $base  the common audit_logs columns
     * @param  array<string, mixed>  $changes  the redacted diff/snapshot
     */
    public static function recordModelChange(Model $model, AuditAction $action, array $base, array $changes): bool
    {
        $fields = self::fieldsFor($model::class);
        $studentId = $fields === [] ? null : self::studentIdFor($model);

        if ($studentId === null) {
            return false;
        }

        $entity = self::entityFor($model::class);
        $label = self::labelFor($model);

        if ($action !== AuditAction::Updated) {
            // What was added/removed: the fields that held a value (empty
            // ones would only be noise in the history).
            $values = [];
            foreach (self::presentValues($model, array_keys($fields)) as $field => $value) {
                $values[] = $action === AuditAction::Created
                    ? self::entry($field, null, $value)
                    : self::entry($field, $value, null);
            }

            AuditLog::create([
                ...$base,
                'changes' => $changes,
                'student_id' => $studentId,
                'section' => self::defaultSectionFor($model::class),
                'details' => ['entity' => $entity, 'label' => $label, 'changes' => $values],
            ]);

            return true;
        }

        $bySection = [];
        foreach (array_keys($model->getChanges()) as $field) {
            if (! isset($fields[$field])) {
                continue;
            }

            $bySection[$fields[$field]->value][] = self::entry(
                $field,
                self::normalize($model, $field, $model->getOriginal($field)),
                self::normalize($model, $field, $model->getAttribute($field)),
            );
        }

        if ($bySection === []) {
            return false;
        }

        // `changes` keeps its previous meaning (the full redacted diff, which
        // other features read): each row carries its own section's fields,
        // and the first one also carries the non-displayable ones
        // (resolved_by_id, *_at…) so nothing is lost from it.
        $unmapped = array_diff_key($changes, $fields);
        $first = true;

        foreach ($bySection as $section => $entries) {
            $own = array_intersect_key($changes, array_flip(array_column($entries, 'field')));

            AuditLog::create([
                ...$base,
                'changes' => $first ? [...$own, ...$unmapped] : $own,
                'student_id' => $studentId,
                'section' => $section,
                'details' => ['entity' => $entity, 'label' => $label, 'changes' => $entries],
            ]);

            $first = false;
        }

        return true;
    }

    /**
     * Record a change that fires no model event (a pivot attach/update).
     * `$changes` is a list of [field, before, after] triples using field
     * names from {@see self::fieldsFor()} for `$auditable`'s class.
     *
     * @param  list<array{0: string, 1: mixed, 2: mixed}>  $changes
     */
    public static function recordManual(
        Model $auditable,
        int $studentId,
        StudentFileSection $section,
        AuditAction $action,
        string $entity,
        ?string $label,
        array $changes,
    ): void {
        $entries = array_map(fn (array $change): array => self::entry(...$change), $changes);

        AuditLog::create([
            ...self::baseAttributes($auditable, $action),
            'changes' => array_combine(
                array_column($entries, 'field'),
                array_map(fn (array $e): array => ['before' => $e['before'] ?? null, 'after' => $e['after'] ?? null], $entries),
            ),
            'student_id' => $studentId,
            'section' => $section,
            'details' => ['entity' => $entity, 'label' => self::truncate($label), 'changes' => $entries],
        ]);
    }

    /**
     * Record a change to an observation's categories (ClickUp 86e3qkwmc,
     * thread of 15/10: "que se registre como una modificación más"). The
     * categories are a pivot, so no model event fires: whoever changes them
     * calls this with the category names before and after. A comment that is
     * not on a student, or whose categories did not actually change, writes
     * nothing.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function recordCommentCategoriesChange(Comment $comment, array $before, array $after): void
    {
        $studentId = self::studentIdFor($comment);

        if ($studentId === null || self::sortedNames($before) === self::sortedNames($after)) {
            return;
        }

        self::recordManual(
            $comment,
            $studentId,
            StudentFileSection::Observations,
            AuditAction::Updated,
            self::entityFor(Comment::class),
            self::labelFor($comment),
            [['categories', $before, $after]],
        );
    }

    /**
     * Category names of a comment, in the order the school lists them.
     *
     * @return list<string>
     */
    public static function categoryNamesOf(Comment $comment): array
    {
        return $comment->categories
            ->sortBy([['position', 'asc'], ['name', 'asc']])
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The audit_logs columns common to every row: tenant, subject, actor
     * (and the role they acted with), origin and timestamp.
     *
     * @return array<string, mixed>
     */
    public static function baseAttributes(Model $model, AuditAction $action): array
    {
        $user = Auth::user() ?? Auth::guard('sanctum')->user();

        return [
            'school_id' => $model->getAttribute('school_id') ?? Tenancy::schoolId(),
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'user_id' => $user?->id,
            'actor_role' => $user instanceof User ? $user->getRoleNames()->first() : null,
            'origin' => $user !== null ? AuditOrigin::User : AuditOrigin::System,
            'created_at' => now(),
        ];
    }

    /**
     * The human-recognisable name of the element that changed, as data (the
     * accommodation's type, the start of an observation…), or null when the
     * section/entity alone identifies it.
     */
    private static function labelFor(Model $model): ?string
    {
        $label = match (true) {
            $model instanceof Accommodation => $model->type,
            $model instanceof AccommodationInstanceOverride => Accommodation::withTrashed()->whereKey($model->accommodation_id)->value('type'),
            $model instanceof Barrier => $model->description,
            $model instanceof Comment => $model->content,
            $model instanceof ScheduledFollowUp => $model->description,
            $model instanceof Alert => $model->description,
            default => null,
        };

        return self::truncate($label);
    }

    /**
     * The displayable fields of `$model` that hold a value — what an addition
     * added or a removal removed (empty ones would only be noise).
     *
     * An observation's categories are a pivot, so they are only included when
     * the relation is already loaded: the comment controllers load it before
     * saving, which is how a new observation's entry carries its categories
     * (the pivot rows themselves are written right after the `created` event).
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function presentValues(Model $model, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            if ($field === 'categories' && $model instanceof Comment) {
                if ($model->relationLoaded('categories') && $model->categories->isNotEmpty()) {
                    $values[$field] = self::categoryNamesOf($model);
                }

                continue;
            }

            if (array_key_exists($field, $model->getAttributes()) && $model->getAttribute($field) !== null) {
                $values[$field] = self::normalize($model, $field, $model->getAttribute($field));
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function sortedNames(array $names): array
    {
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $field, mixed $before, mixed $after): array
    {
        if (in_array($field, self::REDACTED, true)) {
            return ['field' => $field, 'redacted' => true];
        }

        return ['field' => $field, 'before' => $before, 'after' => $after];
    }

    private static function normalize(Model $model, string $field, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $cast = $model->getCasts()[$field] ?? null;

            return $cast === 'date' ? $value->format('Y-m-d') : $value->format(DATE_ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private static function truncate(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Str::limit($value, self::LABEL_LENGTH);
    }
}
