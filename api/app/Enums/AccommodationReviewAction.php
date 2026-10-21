<?php

namespace App\Enums;

/**
 * One step in the history of a direction proposal (ClickUp 86e3qkwkk). Every
 * step of the back-and-forth is kept on the same proposal, with who did it
 * and when — see AccommodationReviewEvent.
 */
enum AccommodationReviewAction: string
{
    case Submitted = 'submitted';
    case Edited = 'edited';
    case Returned = 'returned';
    case Resubmitted = 'resubmitted';
    case Confirmed = 'confirmed';
    case Withdrawn = 'withdrawn';
    case Rejected = 'rejected';
}
