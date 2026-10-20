<?php

namespace App\Actions\AI;

use App\Enums\AccommodationNarrativeStatus;
use App\Enums\AIProposalType;
use App\Enums\AssessmentType;
use App\Models\Accommodation;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\GroupAccommodationNarrative;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Unit;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Assembles the (dynamic) context that gets sent to the model for one
 * AIProposal — kept out of GenerateAIProposalJob so it can be tested in
 * isolation without mocking HTTP (docs/prompts/05-asistente-ia-docente.md §4).
 *
 * PII minimisation is the whole point of this class (CLAUDE.md security rule
 * 11): student learning profiles are only ever emitted as an aggregated,
 * anonymised cohort summary, and even a single personalised target student is
 * referenced by a short opaque id ("Student #1") — a `full_name` never leaves
 * the database. The fixed DUA/UDL system instruction does NOT live here; it is
 * the same for every request and belongs in the Job's system prompt.
 */
class BuildProposalContext
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(AIProposal $proposal): array
    {
        $group = $proposal->group;
        $parameters = $proposal->input_parameters ?? [];
        $inScope = $this->accommodationsInScope($parameters);

        return [
            'group' => [
                'name' => $group->name,
                'level' => $group->level,
                'school_year' => $group->school_year,
                'profile' => $group->group_profile,
            ],
            'curricular_framework' => $this->curricularContext($group, $parameters),
            'subject_program' => $this->subjectProgram($proposal, $parameters),
            'student_cohort_summary' => $this->cohortSummary($group, $inScope),
            'target_student' => $this->targetStudent($parameters, $inScope),
            'group_accommodation_summary' => $this->groupAccommodationSummary($proposal),
            'recent_performance' => $this->recentPerformance($proposal, $parameters),
            'reference_example' => $this->referenceExample($proposal),
        ];
    }

    /**
     * The last accommodation summary psychopedagogy published for the group
     * (ClickUp 86e3jpzdr), for class and assessment drafts only. It never
     * names a student — GenerateGroupAccommodationNarrativeJob discards any
     * text that does — so it can be sent as is. Drafts are not sent: only
     * what psychopedagogy released to the group's teachers. Null when there
     * is none; the proposal is generated anyway.
     *
     * @return array<string, mixed>|null
     */
    protected function groupAccommodationSummary(AIProposal $proposal): ?array
    {
        if (! in_array($proposal->type, [AIProposalType::ClassSession, AIProposalType::Assessment], true)) {
            return null;
        }

        $published = GroupAccommodationNarrative::query()
            ->where('group_id', $proposal->group_id)
            ->where('status', AccommodationNarrativeStatus::Published)
            ->latest('published_at')
            ->first();

        if ($published === null || blank($published->content)) {
            return null;
        }

        return [
            'published_at' => $published->published_at?->toDateString(),
            'text' => $published->content,
        ];
    }

    /**
     * The group's latest administered assessments in the requested subject,
     * as aggregates only (results count, average, lowest, highest) — never a
     * student or a single score tied to one (CLAUDE.md security rule 11). Lets
     * the model explain its proposal from how the group has been doing.
     * Class and assessment drafts only; empty when nothing was graded yet.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<int, array<string, mixed>>
     */
    protected function recentPerformance(AIProposal $proposal, array $parameters): array
    {
        if (! in_array($proposal->type, [AIProposalType::ClassSession, AIProposalType::Assessment], true)) {
            return [];
        }

        $subjectId = $this->subjectId($parameters);

        if ($subjectId === null) {
            return [];
        }

        return Assessment::query()
            ->where('group_id', $proposal->group_id)
            ->where('subject_id', $subjectId)
            ->whereNotNull('administered_at')
            ->whereHas('results', fn ($query) => $query->whereNotNull('score'))
            ->withCount(['results' => fn ($query) => $query->whereNotNull('score')])
            ->withAvg('results', 'score')
            ->withMin('results', 'score')
            ->withMax('results', 'score')
            ->latest('administered_at')
            ->limit(self::MAX_RECENT_ASSESSMENTS)
            ->get()
            ->map(fn (Assessment $assessment): array => [
                'type' => $assessment->type->value,
                'purpose' => $assessment->purpose,
                'administered_at' => $assessment->administered_at?->toDateString(),
                'results_count' => (int) $assessment->results_count,
                'average_score' => round((float) $assessment->results_avg_score, 2),
                'lowest_score' => (float) $assessment->results_min_score,
                'highest_score' => (float) $assessment->results_max_score,
            ])
            ->values()
            ->all();
    }

    /**
     * Relevant slice of the curricular tree for the group's frameworks.
     *
     * v1 heuristic (replaceable): take the top-level items of every catalog in
     * every framework linked to the group, plus their direct children, and —
     * when the request carries a `subject_id` (resolved to the subject name) or
     * a `focus` — bubble up any item whose name or code loosely matches.
     * Deliberately NOT the full catalog (which
     * would blow up the prompt); refine with real pedagogical relevance later.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function curricularContext(Group $group, array $parameters): array
    {
        // The subject label is now referenced by id; resolve it to the subject's
        // name (the only thing sent to the model — a name, never student PII).
        $subjectName = isset($parameters['subject_id'])
            ? (Subject::find($parameters['subject_id'])?->name ?? '')
            : '';
        $needle = mb_strtolower(trim((string) ($subjectName !== '' ? $subjectName : ($parameters['focus'] ?? ''))));

        $frameworks = $group->curricularFrameworks()->with('catalogs.items')->get();

        $items = $frameworks
            ->flatMap(fn ($framework) => $framework->catalogs)
            ->flatMap(fn ($catalog) => $catalog->items)
            ->filter(function (CurricularItem $item) use ($needle): bool {
                // Top-level nodes and their direct children are always kept as
                // the structural backbone; deeper nodes only if they match.
                $isBackbone = $item->parent_id === null
                    || $item->parent?->parent_id === null;

                if ($isBackbone) {
                    return true;
                }

                return $needle !== '' && str_contains(
                    mb_strtolower($item->name.' '.$item->code),
                    $needle,
                );
            })
            ->take(self::MAX_CURRICULAR_ITEMS)
            ->map(fn (CurricularItem $item): array => [
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type->value,
                'description' => $item->description,
            ])
            ->values()
            ->all();

        return [
            'frameworks' => $frameworks->pluck('name')->all(),
            'relevant_items' => $items,
        ];
    }

    /**
     * Which accommodations may shape this proposal: the ones in force today
     * (Accommodation::scopeEffective — active, and approved when approval is
     * required — and already started), and, when the request is about one
     * subject, only the general ones plus those scoped to that subject; when
     * it drafts an assessment of a given type, only those covering that type.
     * Same scope rule the teacher sees in the student profile (ClickUp
     * 86e3dpbwy follow-up) — an accommodation for another subject would steer
     * the draft wrongly.
     *
     * @param  array<string, mixed>  $parameters
     * @return Closure(HasMany<Accommodation, Student>): void
     */
    protected function accommodationsInScope(array $parameters): Closure
    {
        $subjectId = $this->subjectId($parameters);
        $assessmentType = AssessmentType::tryFrom((string) ($parameters['assessment_type'] ?? ''));

        return function (HasMany $query) use ($subjectId, $assessmentType): void {
            $query->effective()->started();

            if ($subjectId !== null) {
                $query->appliesToSubject($subjectId);
            }

            if ($assessmentType !== null) {
                $query->appliesToAssessmentType($assessmentType);
            }
        };
    }

    /**
     * The subject the request is about: given directly (annual plan,
     * assessment) or inherited from the annual plan a unit/class session
     * belongs to. Null when the request isn't tied to one subject.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function subjectId(array $parameters): ?int
    {
        if (isset($parameters['subject_id'])) {
            return (int) $parameters['subject_id'];
        }

        $annualPlanId = $parameters['annual_plan_id']
            ?? (isset($parameters['unit_id']) ? Unit::query()->whereKey($parameters['unit_id'])->value('annual_plan_id') : null);

        if ($annualPlanId === null) {
            return null;
        }

        $subjectId = AnnualPlan::query()->whereKey($annualPlanId)->value('subject_id');

        return $subjectId === null ? null : (int) $subjectId;
    }

    /**
     * The program of the requested subject (ClickUp 86e3dt6ag). Once the
     * subject is linked to the curricular catalog, the catalog is the source
     * (its units/objectives subtree) and the school's PDF is no longer used;
     * until then, the text extracted from the program PDF the school uploaded
     * is sent as provisional context, so a teacher never plans without a
     * program. A curriculum document — no student data. The PDF text is only
     * sent when the requesting user may open that PDF themselves
     * (SubjectPolicy::viewSyllabus), so the assistant can't be used to read a
     * program they have no access to.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|null
     */
    protected function subjectProgram(AIProposal $proposal, array $parameters): ?array
    {
        $subject = $this->plannedSubject($parameters);

        if ($subject === null) {
            return null;
        }

        if ($subject->curricularItem !== null) {
            $item = $subject->curricularItem;

            return [
                'source' => 'curricular_catalog',
                'subject' => $subject->name,
                'items' => $this->boundedSubtree($item)
                    ->map(fn (CurricularItem $node): array => [
                        'code' => $node->code,
                        'name' => $node->name,
                        'type' => $node->type->value,
                        'description' => $node->description,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $requester = $proposal->requestedBy;

        if ($subject->syllabus_text === null || $requester === null || ! $requester->can('viewSyllabus', $subject)) {
            return null;
        }

        return [
            'source' => 'school_program_pdf',
            'subject' => $subject->name,
            'text' => mb_substr($subject->syllabus_text, 0, self::MAX_PROGRAM_TEXT_LENGTH),
        ];
    }

    /**
     * The subject being planned. Annual-plan and assessment requests carry
     * `subject_id`; unit and class-session requests don't — they plan inside
     * an annual plan (`annual_plan_id`, or `unit_id` → its plan), so the
     * subject is that plan's. AnnualPlan is tenant-scoped, so a plan (or a
     * unit's plan) from another school resolves to no subject.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function plannedSubject(array $parameters): ?Subject
    {
        if (isset($parameters['subject_id'])) {
            return Subject::find($parameters['subject_id']);
        }

        $plan = match (true) {
            isset($parameters['annual_plan_id']) => AnnualPlan::find($parameters['annual_plan_id']),
            isset($parameters['unit_id']) => Unit::find($parameters['unit_id'])?->annualPlan,
            default => null,
        };

        return $plan?->subject;
    }

    /**
     * $root and its descendants, level by level (units before their
     * objectives), stopping at MAX_CURRICULAR_ITEMS — one query per level,
     * never walking the rest of a large subtree.
     *
     * @return Collection<int, CurricularItem>
     */
    protected function boundedSubtree(CurricularItem $root): Collection
    {
        $items = collect([$root]);
        $level = collect([$root]);

        while ($level->isNotEmpty() && $items->count() < self::MAX_CURRICULAR_ITEMS) {
            $level = CurricularItem::query()
                ->whereIn('parent_id', $level->pluck('id'))
                ->orderBy('id')
                ->limit(self::MAX_CURRICULAR_ITEMS - $items->count())
                ->get();
            $items = $items->concat($level);
        }

        return $items->values();
    }

    /**
     * Aggregated, anonymised summary of the group's learners — counts only,
     * never names (CLAUDE.md security rule 11).
     *
     * @param  Closure(HasMany<Accommodation, Student>): void  $inScope
     * @return array<string, mixed>
     */
    protected function cohortSummary(Group $group, Closure $inScope): array
    {
        $students = $group->students()->with(['accommodations' => $inScope])->get();

        $accommodationsByFocusArea = $students
            ->flatMap(fn (Student $student) => $student->accommodations)
            ->groupBy('focus_area')
            ->map->count();

        return [
            'total_students' => $students->count(),
            'with_active_accommodations' => $students
                ->filter(fn (Student $student) => $student->accommodations->isNotEmpty())
                ->count(),
            'active_accommodations_by_focus_area' => $accommodationsByFocusArea,
            'with_therapeutic_companion' => $students
                ->where('has_therapeutic_companion', true)
                ->count(),
        ];
    }

    /**
     * When the request targets one student (an individualised AnnualPlan,
     * `student_id` set), send THAT student's learning profile — but referenced
     * by a short opaque id, never by name. The real name is resolved only on
     * the application side when showing the result.
     *
     * @param  array<string, mixed>  $parameters
     * @param  Closure(HasMany<Accommodation, Student>): void  $inScope
     * @return array<string, mixed>|null
     */
    protected function targetStudent(array $parameters, Closure $inScope): ?array
    {
        $studentId = $parameters['student_id'] ?? null;

        if ($studentId === null) {
            return null;
        }

        $student = Student::query()
            ->with(['accommodations' => $inScope])
            ->find($studentId);

        if ($student === null) {
            return null;
        }

        return [
            'ref' => 'Student #1',
            'has_therapeutic_companion' => $student->has_therapeutic_companion,
            'learning_profile' => $student->learning_profile,
            'individual_profile' => $student->individual_profile,
            'active_accommodations' => $student->accommodations
                ->map(fn ($accommodation): array => [
                    'focus_area' => $accommodation->focus_area,
                    'type' => $accommodation->type,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * If the teacher pointed at an earlier proposal as a reference, include its
     * applied draft as a few-shot example. Scoped to `completed`/`applied`
     * proposals only — a pending/errored one has nothing useful to show.
     *
     * @return array<string, mixed>|null
     */
    protected function referenceExample(AIProposal $proposal): ?array
    {
        $referenceId = $proposal->input_parameters['reference_proposal_id'] ?? null;

        if ($referenceId === null) {
            return null;
        }

        // Tenant scope keeps this within the same school; no cross-tenant read.
        $reference = AIProposal::query()->find($referenceId);

        if ($reference === null || $reference->raw_response === null) {
            return null;
        }

        return [
            'type' => $reference->type->value,
            'draft' => $reference->raw_response,
        ];
    }

    /** v1 cap so a large catalog can't blow up the prompt. Tune with real data. */
    protected const MAX_CURRICULAR_ITEMS = 50;

    /** How many past assessments of the subject feed `recent_performance`. */
    protected const MAX_RECENT_ASSESSMENTS = 3;

    /** Cap on the program PDF text sent to the model (≈ 3–4k tokens). */
    protected const MAX_PROGRAM_TEXT_LENGTH = 15_000;
}
