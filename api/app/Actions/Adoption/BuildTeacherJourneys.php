<?php

namespace App\Actions\Adoption;

use App\Enums\JourneyStep;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\School;
use App\Models\Subject;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The creation journeys behind one teacher's counts for a month (ClickUp
 * 86e3qkxtc, screen 28), for Dirección's "Ver recorridos".
 *
 * A journey is every step row sharing (teacher, journey_id) — a journey is
 * listed when any of its steps falls in the month, and then shown whole. A
 * confirmed creation recorded without a journey id (before journeys existed,
 * or a client that didn't send one) is its own one-step journey, so the
 * list always explains every confirmed creation the month counts.
 *
 * Output is deliberately minimal: step names, timestamps, a failure
 * *category* (never the raw error), and the content's type/title/group/
 * subject. No prompts, no AI responses, no student data.
 */
class BuildTeacherJourneys
{
    /**
     * Content types whose confirmed creation can be linked to its model.
     *
     * @var array<string, class-string<Model>>
     */
    protected const CONTENT_MODELS = [
        'annual_plan' => AnnualPlan::class,
        'class_session' => ClassSession::class,
        'assessment' => Assessment::class,
    ];

    /**
     * @var list<string>
     */
    protected const GENERATION_STEPS = [
        'generation_requested',
        'regeneration_requested',
        'generation_succeeded',
        'generation_failed',
        'draft_discarded',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(School $school, User $teacher, User $viewer, Carbon $monthStart): array
    {
        $monthEnd = $monthStart->copy()->endOfMonth();

        $base = fn () => UsageEvent::query()
            ->where('school_id', $school->id)
            ->where('user_id', $teacher->id)
            ->whereNotNull('step');

        $inMonth = $base()->whereBetween('created_at', [$monthStart, $monthEnd])->get();

        $journeyIds = $inMonth->pluck('journey_id')->filter()->unique()->values();

        $events = $inMonth
            ->whereNull('journey_id')
            ->concat($journeyIds->isEmpty() ? [] : $base()->whereIn('journey_id', $journeyIds)->get())
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();

        $journeys = $events->groupBy(fn (UsageEvent $event) => $event->journey_id ?? 'event-'.$event->id);

        $groups = Group::query()
            ->whereIn('id', $events->pluck('group_id')->filter()->unique())
            ->pluck('name', 'id');

        $contents = $this->loadContents($events);
        $proposalSubjects = $this->proposalSubjects($events);

        return $journeys
            ->map(fn (Collection $steps, string $key) => $this->journey(
                $key, $steps, $groups, $contents, $proposalSubjects, $viewer,
            ))
            ->sortByDesc('started_at')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, UsageEvent>  $steps
     * @param  Collection<int, string>  $groups
     * @param  array<string, array<int, Model>>  $contents
     * @param  array<int, string>  $proposalSubjects  ai_proposal_id => subject name
     * @return array<string, mixed>
     */
    protected function journey(
        string $key,
        Collection $steps,
        Collection $groups,
        array $contents,
        array $proposalSubjects,
        User $viewer,
    ): array {
        $confirmation = $steps->firstWhere('step', JourneyStep::CreationConfirmed->value);
        $stepNames = $steps->pluck('step');

        $flow = match (true) {
            $stepNames->intersect(self::GENERATION_STEPS)->isNotEmpty() => 'ai',
            $stepNames->intersect([JourneyStep::FormOpened->value, JourneyStep::FillingStarted->value])->isNotEmpty() => 'manual',
            default => null,
        };

        // The journey is about what its first step was about (the form's or
        // the AI proposal's type). Applying a unit proposal confirms the
        // class sessions it seeds, not a unit: such a journey stays a unit
        // one and links/titles none of those sessions as if it were them.
        $contentType = $steps->first()->content_type;
        $content = $confirmation === null || $confirmation->content_type !== $contentType
            ? null
            : $this->contentFor($confirmation, $contents);

        $groupId = $steps->pluck('group_id')->filter()->first();
        $groupName = $content?->getRelationValue('group')?->name
            ?? ($groupId === null ? null : $groups->get($groupId));

        $subjectName = $content?->getRelationValue('subject')?->name
            ?? $steps
                ->map(fn (UsageEvent $event) => $proposalSubjects[$event->metadata['ai_proposal_id'] ?? 0] ?? null)
                ->filter()
                ->first();

        $serializedSteps = $steps
            ->map(fn (UsageEvent $event) => array_filter([
                'step' => $event->step,
                'at' => $event->created_at->toIso8601String(),
                // A fixed category (see GenerateAIProposalJob::markError),
                // never the technical error message.
                'failure_reason' => $event->step === JourneyStep::GenerationFailed->value
                    ? ($event->metadata['failure_reason'] ?? null)
                    : null,
            ], fn ($value) => $value !== null))
            ->values();

        $canView = $content !== null && $viewer->can('view', $content);

        return [
            'id' => $key,
            'content_type' => $contentType,
            'flow' => $flow,
            'started_at' => $serializedSteps->first()['at'],
            'confirmed' => $confirmation !== null,
            'last_step' => $serializedSteps->last(),
            'steps' => $serializedSteps->all(),
            'title' => $content?->getAttribute('title'),
            'group_name' => $groupName,
            'subject_name' => $subjectName,
            'content' => $canView ? [
                'type' => $contentType,
                'id' => $content->getKey(),
                'group_id' => $content->getAttribute('group_id'),
            ] : null,
        ];
    }

    /**
     * The created rows behind every confirmation, keyed by content type then
     * id. Rows deleted since simply aren't found (no link, no title).
     *
     * @param  Collection<int, UsageEvent>  $events
     * @return array<string, array<int, Model>>
     */
    protected function loadContents(Collection $events): array
    {
        $byType = $events
            ->where('step', JourneyStep::CreationConfirmed->value)
            ->groupBy('content_type');

        $contents = [];
        foreach (self::CONTENT_MODELS as $type => $model) {
            $ids = ($byType->get($type) ?? collect())
                ->map(fn (UsageEvent $event) => $this->contentId($event))
                ->filter()
                ->unique();

            if ($ids->isEmpty()) {
                continue;
            }

            $query = $model::query()->with('group:id,name')->whereKey($ids);
            if ($model !== ClassSession::class) {
                $query->with('subject:id,name');
            }

            $contents[$type] = $query->get()->keyBy(fn (Model $row) => $row->getKey())->all();
        }

        return $contents;
    }

    /**
     * Subject names of the AI proposals referenced by the steps, from the
     * proposal's own `subject_id` parameter only (never its other inputs).
     *
     * @param  Collection<int, UsageEvent>  $events
     * @return array<int, string>
     */
    protected function proposalSubjects(Collection $events): array
    {
        $proposalIds = $events
            ->map(fn (UsageEvent $event) => $event->metadata['ai_proposal_id'] ?? null)
            ->filter()
            ->unique();

        if ($proposalIds->isEmpty()) {
            return [];
        }

        $subjectByProposal = AIProposal::query()
            ->whereKey($proposalIds)
            ->get(['id', 'input_parameters'])
            ->mapWithKeys(fn (AIProposal $proposal) => [$proposal->id => $proposal->input_parameters['subject_id'] ?? null])
            ->filter();

        $names = Subject::query()->whereKey($subjectByProposal->unique())->pluck('name', 'id');

        return $subjectByProposal
            ->map(fn ($subjectId) => $names->get($subjectId))
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, array<int, Model>>  $contents
     */
    protected function contentFor(UsageEvent $confirmation, array $contents): ?Model
    {
        $id = $this->contentId($confirmation);

        return $id === null ? null : ($contents[$confirmation->content_type][$id] ?? null);
    }

    /**
     * Rows written before journeys existed keep the id in `metadata` only.
     */
    protected function contentId(UsageEvent $event): ?int
    {
        $id = $event->content_id ?? ($event->metadata['id'] ?? null);

        return $id === null ? null : (int) $id;
    }
}
