<?php

namespace App\Enums;

/**
 * Lifecycle of a follow-up stage (ClickUp 86e3qkwna).
 *
 * - Pending: still to be done; blocks resolving the follow-up.
 * - Done: closed by its responsible (or a general responsible) with a
 *   conclusion of what was done.
 * - Split: subdivided into child stages. The original row is kept — with its
 *   notes and history — but no longer counts as pending work; its children
 *   (`parent_stage_id`) carry it from there.
 */
enum FollowUpStageStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Split = 'split';
}
