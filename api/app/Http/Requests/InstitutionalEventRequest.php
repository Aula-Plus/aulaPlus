<?php

namespace App\Http\Requests;

use App\Models\CalendarEvent;
use App\Models\SchoolGrade;
use App\Services\Calendar\EventAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST /calendar-events) or edit (PATCH /calendar-events/{event}) an
 * institutional event: what it is, a date, an optional time, an optional
 * place and description (P21-DEC-05). Direction only — CalendarEventPolicy.
 * Dates and times are school-local.
 *
 * `audience` (ClickUp 1dfd3mc55zp): who it concerns, as a list of targets —
 * `{level_id}` (a level), `{grade_id}` (a generation) or `{grade_id,
 * framework_id}` (the groups of a program within a generation). Empty = the
 * whole school; omitted on create = the whole school, on edit = unchanged.
 * Each program must be one the caller's school offers besides its mandatory
 * regime and apply to the grade's level (see EventAudience::options).
 */
class InstitutionalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CalendarEvent|null $event */
        $event = $this->route('calendarEvent');

        if ($event === null) {
            return $this->user()->can('create', CalendarEvent::class);
        }

        // A meeting the caller is not invited to does not exist for them
        // (screen 21 red line): 404, not 403.
        abort_unless($this->user()->can('view', $event), 404);

        // This endpoint edits institutional events only.
        return $event->isInstitutional() && $this->user()->can('update', $event);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'date' => [$required, 'date_format:Y-m-d'],
            'time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'location' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'array', 'max:'.self::MAX_TARGETS],
            'audience.*' => ['array:level_id,grade_id,framework_id'],
            'audience.*.level_id' => ['nullable', 'integer', Rule::exists('education_levels', 'id')],
            'audience.*.grade_id' => ['nullable', 'integer', Rule::exists('school_grades', 'id')],
            'audience.*.framework_id' => ['nullable', 'integer'],
        ];
    }

    public const MAX_TARGETS = 30;

    /**
     * Each target is exactly one kind; a program needs its generation, must
     * be offered by this school and apply to that grade's level; no target is
     * repeated.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $targets = $this->input('audience');
                if (! is_array($targets) || $validator->errors()->has('audience*')) {
                    return;
                }

                $frameworks = app(EventAudience::class)
                    ->pickableFrameworks($this->user()->school)
                    ->keyBy('id');
                $grades = SchoolGrade::query()->with('educationLevel')
                    ->whereKey(array_filter(array_column($targets, 'grade_id')))
                    ->get()
                    ->keyBy('id');
                // On edit, a program already stored on this event for that
                // grade stays valid even if the school stopped offering it,
                // so editing the title does not lock the event.
                /** @var CalendarEvent|null $event */
                $event = $this->route('calendarEvent');
                $stored = $event === null ? collect() : $event->audiences()
                    ->whereNotNull('curricular_framework_id')
                    ->get(['school_grade_id', 'curricular_framework_id'])
                    ->map(fn ($row): string => ((int) $row->school_grade_id).'-'.((int) $row->curricular_framework_id))
                    ->flip();
                $seen = [];

                foreach (array_values($targets) as $index => $target) {
                    $level = $target['level_id'] ?? null;
                    $grade = $target['grade_id'] ?? null;
                    $framework = $target['framework_id'] ?? null;

                    if (($level === null) === ($grade === null)) {
                        $validator->errors()->add("audience.{$index}", 'Each target is either a level or a grade.');

                        continue;
                    }

                    if ($framework !== null && ! ($grade !== null && $stored->has(((int) $grade).'-'.((int) $framework)))) {
                        if ($grade === null) {
                            $validator->errors()->add("audience.{$index}.framework_id", 'A program is chosen within a grade.');

                            continue;
                        }
                        $program = $frameworks->get((int) $framework);
                        if ($program === null) {
                            $validator->errors()->add("audience.{$index}.framework_id", 'The program is not offered by the school.');

                            continue;
                        }
                        if (! $program->appliesToLevel($grades->get((int) $grade)?->educationLevel?->key)) {
                            $validator->errors()->add("audience.{$index}.framework_id", 'The program does not apply to that grade.');

                            continue;
                        }
                    }

                    $key = implode('-', [(int) $level, (int) $grade, (int) $framework]);
                    if (isset($seen[$key])) {
                        $validator->errors()->add("audience.{$index}", 'The target is repeated.');
                    }
                    $seen[$key] = true;
                }
            },
        ];
    }

    /**
     * The validated targets, or null when the request did not send them.
     *
     * @return list<array{level_id: int|null, grade_id: int|null, framework_id: int|null}>|null
     */
    public function audience(): ?array
    {
        if (! $this->has('audience')) {
            return null;
        }

        return array_values(array_map(fn (array $target): array => [
            'level_id' => isset($target['level_id']) ? (int) $target['level_id'] : null,
            'grade_id' => isset($target['grade_id']) ? (int) $target['grade_id'] : null,
            'framework_id' => isset($target['framework_id']) ? (int) $target['framework_id'] : null,
        ], $this->validated('audience') ?? []));
    }
}
