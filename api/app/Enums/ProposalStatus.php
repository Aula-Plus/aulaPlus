<?php

namespace App\Enums;

/**
 * Review state of an accommodation proposal. Nothing is in force until
 * psychopedagogy approves (or modifies) it, which creates the accommodation.
 */
enum ProposalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Modified = 'modified';
    case Rejected = 'rejected';
}
