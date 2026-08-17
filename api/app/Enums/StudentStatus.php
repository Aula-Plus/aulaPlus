<?php

namespace App\Enums;

/**
 * Whether a student is currently enrolled. Deactivating a student (e.g. they
 * left the school) flips this instead of deleting the row — history stays
 * intact. Hard delete remains available (director-only) for load mistakes.
 */
enum StudentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
