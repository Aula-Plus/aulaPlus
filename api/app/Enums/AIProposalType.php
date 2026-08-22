<?php

namespace App\Enums;

/**
 * The kind of planning entity an AIProposal drafts (docs/prompts/
 * 05-asistente-ia-docente.md §2). Each value maps to a real domain model
 * created when the proposal is applied: AnnualPlan, Unit, ClassSession or
 * Assessment.
 */
enum AIProposalType: string
{
    case AnnualPlan = 'annual_plan';
    case Unit = 'unit';
    case ClassSession = 'class_session';
    case Assessment = 'assessment';
}
