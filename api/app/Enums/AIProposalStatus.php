<?php

namespace App\Enums;

/**
 * Lifecycle of an AIProposal (docs/prompts/05-asistente-ia-docente.md §2):
 * `pending` while the generation Job is queued/running, `completed` once a
 * valid draft is parsed, `error` if generation fails, then a terminal
 * `applied` (turned into a real entity by the requesting teacher) or
 * `discarded`.
 */
enum AIProposalStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
    case Discarded = 'discarded';
    case Applied = 'applied';
}
