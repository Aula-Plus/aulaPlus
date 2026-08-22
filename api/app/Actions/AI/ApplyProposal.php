<?php

namespace App\Actions\AI;

use App\Enums\AIProposalStatus;
use App\Enums\AIProposalType;
use App\Enums\ClassSessionStatus;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\CurricularItem;
use App\Models\Unit;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Turns a completed AIProposal draft into the real domain entity
 * (docs/prompts/05-asistente-ia-docente.md §5). Kept out of the controller so
 * the per-type mapping can be tested in isolation.
 *
 * Everything runs in one DB transaction. Authorship of the created entity
 * points at whoever applies it (`$applier`), never "the system" — the final
 * decision is the teacher's. A dedicated `ai_proposal.applied` usage event is
 * logged in addition to the child entity's own `*.created` event (the
 * LogsUsageEvents trait fires that one automatically inside the authenticated
 * apply request): two distinct, complementary signals.
 */
class ApplyProposal
{
    public function __invoke(AIProposal $proposal, User $applier): Model
    {
        return DB::transaction(function () use ($proposal, $applier): Model {
            $entity = match ($proposal->type) {
                AIProposalType::AnnualPlan => $this->applyAnnualPlan($proposal, $applier),
                AIProposalType::Unit => $this->applyUnit($proposal),
                AIProposalType::ClassSession => $this->applyClassSession($proposal),
                AIProposalType::Assessment => $this->applyAssessment($proposal, $applier),
            };

            $proposal->update([
                'status' => AIProposalStatus::Applied,
                'applied_to_id' => $entity->getKey(),
                'applied_to_type' => $entity->getMorphClass(),
            ]);

            UsageEvent::create([
                'user_id' => $applier->id,
                'event_type' => self::APPLIED_EVENT_TYPE,
                // IDs only, never the draft content (CLAUDE.md rules 2 and 11).
                'metadata' => [
                    'ai_proposal_id' => $proposal->id,
                    'applied_to_type' => $entity->getMorphClass(),
                    'applied_to_id' => $entity->getKey(),
                ],
            ]);

            return $entity;
        });
    }

    protected function applyAnnualPlan(AIProposal $proposal, User $applier): AnnualPlan
    {
        $params = $proposal->input_parameters;
        $raw = $proposal->raw_response;

        return AnnualPlan::create([
            'group_id' => $proposal->group_id,
            'curricular_framework_id' => $params['curricular_framework_id'],
            'teacher_id' => $applier->id,
            'student_id' => $params['student_id'] ?? null,
            'subject' => $params['subject'],
            'year' => $params['year'],
            // `language` is NOT NULL on annual_plans but optional in the request;
            // default to the product's language when the teacher left it blank.
            'language' => $params['language'] ?? self::DEFAULT_LANGUAGE,
            'description' => $raw['description'],
        ]);
    }

    protected function applyUnit(AIProposal $proposal): Unit
    {
        $params = $proposal->input_parameters;
        $raw = $proposal->raw_response;

        // units.position/start_date/end_date are NOT NULL. The model is asked
        // for them, but fall back to sane defaults so a partial draft can still
        // be applied (the teacher adjusts dates afterwards).
        $startDate = $raw['suggested_start_date'] ?? now()->toDateString();

        $unit = Unit::create([
            'annual_plan_id' => $params['annual_plan_id'],
            'name' => $raw['name'],
            'position' => $raw['suggested_position'] ?? 1,
            'start_date' => $startDate,
            'end_date' => $raw['suggested_end_date'] ?? $startDate,
        ]);

        $this->syncCurricularItems($unit, $raw['suggested_curricular_items'] ?? []);

        // ClassSessions inherit the group through the plan the unit belongs to.
        // The unit-session schema carries no per-session date, so each planned
        // session is seeded at the unit's start date for the teacher to reschedule.
        $groupId = $unit->loadMissing('annualPlan')->annualPlan->group_id;

        foreach ($raw['sessions'] ?? [] as $session) {
            ClassSession::create([
                'group_id' => $groupId,
                'unit_id' => $unit->id,
                'title' => $session['title'] ?? self::DEFAULT_SESSION_TITLE,
                'objective' => $session['objective'] ?? null,
                'date' => $startDate,
                'duration_minutes' => $session['duration_minutes'] ?? self::DEFAULT_SESSION_MINUTES,
                'status' => ClassSessionStatus::Planned,
            ]);
        }

        return $unit;
    }

    protected function applyClassSession(AIProposal $proposal): ClassSession
    {
        $params = $proposal->input_parameters;
        $raw = $proposal->raw_response;

        // date/duration_minutes/title are NOT NULL on class_sessions; the
        // model's suggested_date may be null, so default to today.
        return ClassSession::create([
            'group_id' => $proposal->group_id,
            'unit_id' => $params['unit_id'] ?? null,
            'title' => $raw['title'] ?? self::DEFAULT_SESSION_TITLE,
            'objective' => $raw['objective'] ?? null,
            'description' => $raw['description'] ?? null,
            'duration_minutes' => $raw['duration_minutes'] ?? self::DEFAULT_SESSION_MINUTES,
            'date' => $raw['suggested_date'] ?? now()->toDateString(),
            'status' => ClassSessionStatus::Planned,
        ]);
    }

    protected function applyAssessment(AIProposal $proposal, User $applier): Assessment
    {
        $params = $proposal->input_parameters;
        $raw = $proposal->raw_response;

        $assessment = Assessment::create([
            'group_id' => $proposal->group_id,
            'teacher_id' => $applier->id,
            'type' => $params['assessment_type'],
            'purpose' => $raw['purpose'] ?? null,
            'duration_minutes' => $raw['duration_minutes'] ?? null,
            'content' => $raw['content'] ?? null,
        ]);

        $this->syncCurricularItems($assessment, $raw['suggested_curricular_items'] ?? []);

        return $assessment;
    }

    /**
     * Resolve the model's suggested curricular item `code`s to real global
     * CurricularItem rows and sync the pivot. Unknown codes are simply
     * dropped — a hallucinated code must never create a row.
     *
     * @param  Unit|Assessment  $entity
     * @param  array<int, string>  $codes
     */
    protected function syncCurricularItems(Model $entity, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $ids = CurricularItem::query()->whereIn('code', $codes)->pluck('id');

        $entity->curricularItems()->sync($ids);
    }

    /** @see docs/prompts/04-seguimiento-institucional.md §5 (usage_events) */
    protected const APPLIED_EVENT_TYPE = 'ai_proposal.applied';

    /** Fallbacks for NOT NULL columns when a draft leaves them out. */
    protected const DEFAULT_LANGUAGE = 'Español';

    protected const DEFAULT_SESSION_MINUTES = 45;

    protected const DEFAULT_SESSION_TITLE = 'Clase';
}
