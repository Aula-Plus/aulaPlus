<?php

namespace App\Enums;

/**
 * Where a direction proposal for an accommodation stands (ClickUp 86e3qkwkk).
 * An accommodation created directly by psychopedagogy carries no review
 * status at all (null) — only direction proposals go through this circuit.
 *
 * - Pending:   sent to psychopedagogy, waiting for its review.
 * - Returned:  psychopedagogy sent it back with a comment; direction's turn.
 * - Confirmed: psychopedagogy confirmed it — from here on it is an ordinary
 *              accommodation and may reach teachers.
 * - Withdrawn: direction withdrew it before confirmation (closed).
 * - Rejected:  psychopedagogy decided it does not apply (closed).
 *
 * Only a null or Confirmed status may ever reach a teacher or count as in
 * force — see Accommodation::scopeConfirmed(). User-facing Spanish labels
 * live in the frontend.
 */
enum AccommodationReviewStatus: string
{
    case Pending = 'pending';
    case Returned = 'returned';
    case Confirmed = 'confirmed';
    case Withdrawn = 'withdrawn';
    case Rejected = 'rejected';

    /**
     * Still under review: direction may edit or withdraw it, psychopedagogy
     * may edit, confirm, return or reject it.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Returned;
    }

    /**
     * Closed without ever becoming an accommodation: kept for the record only.
     */
    public function isClosedUnconfirmed(): bool
    {
        return $this === self::Withdrawn || $this === self::Rejected;
    }
}
