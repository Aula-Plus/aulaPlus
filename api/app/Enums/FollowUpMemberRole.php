<?php

namespace App\Enums;

/**
 * How a person takes part in a follow-up (ClickUp 86e3qkwna). A follow-up
 * always has at least one general responsible; everyone else involved —
 * including whoever is responsible for a single stage — is a participant.
 */
enum FollowUpMemberRole: string
{
    case GeneralResponsible = 'general_responsible';
    case Participant = 'participant';
}
