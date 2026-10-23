<?php

namespace App\Enums;

/**
 * Entries of a follow-up's history (`follow_up_events`). Each one records who
 * (actor) and when; `data` holds ids/dates only, never free text — free text
 * (reasons, a previous conclusion) lives in the event's `note`, which is never
 * logged nor audited (CLAUDE.md security rule 11).
 */
enum FollowUpEventType: string
{
    case Created = 'created';
    case StageAdded = 'stage_added';
    case StageUpdated = 'stage_updated';
    case StageSplit = 'stage_split';
    case StageClosed = 'stage_closed';
    case DeadlineChanged = 'deadline_changed';
    case ParticipantAdded = 'participant_added';
    case ParticipantRemoved = 'participant_removed';
    case GeneralResponsibleAdded = 'general_responsible_added';
    case GeneralResponsibleRemoved = 'general_responsible_removed';
    case Withdrew = 'withdrew';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
}
