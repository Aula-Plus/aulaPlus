<?php

namespace App\Actions\AI;

use App\Models\AIProposal;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\Student;

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

        return [
            'group' => [
                'name' => $group->name,
                'level' => $group->level,
                'school_year' => $group->school_year,
                'profile' => $group->group_profile,
            ],
            'curricular_framework' => $this->curricularContext($group, $parameters),
            'student_cohort_summary' => $this->cohortSummary($group),
            'target_student' => $this->targetStudent($parameters),
            'reference_example' => $this->referenceExample($proposal),
        ];
    }

    /**
     * Relevant slice of the curricular tree for the group's frameworks.
     *
     * v1 heuristic (replaceable): take the top-level items of every catalog in
     * every framework linked to the group, plus their direct children, and —
     * when the request carries a `subject`/`focus` — bubble up any item whose
     * name or code loosely matches. Deliberately NOT the full catalog (which
     * would blow up the prompt); refine with real pedagogical relevance later.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function curricularContext(Group $group, array $parameters): array
    {
        $needle = mb_strtolower(trim((string) ($parameters['subject'] ?? $parameters['focus'] ?? '')));

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
     * Aggregated, anonymised summary of the group's learners — counts only,
     * never names (CLAUDE.md security rule 11).
     *
     * @return array<string, mixed>
     */
    protected function cohortSummary(Group $group): array
    {
        $students = $group->students()->with(['accommodations' => fn ($q) => $q->where('active', true)])->get();

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
     * @return array<string, mixed>|null
     */
    protected function targetStudent(array $parameters): ?array
    {
        $studentId = $parameters['student_id'] ?? null;

        if ($studentId === null) {
            return null;
        }

        $student = Student::query()
            ->with(['accommodations' => fn ($q) => $q->where('active', true)])
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
}
