<?php

namespace App\Enums;

/**
 * Lifecycle of a GroupAccommodationNarrative: `pending` while the generation
 * Job runs, `draft` once a valid text is stored (only psychopedagogy sees it),
 * `published` when psychopedagogy releases it to the group's teachers,
 * `archived` once a newer one replaces it, `error` if generation failed.
 */
enum AccommodationNarrativeStatus: string
{
    case Pending = 'pending';
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
    case Error = 'error';
}
