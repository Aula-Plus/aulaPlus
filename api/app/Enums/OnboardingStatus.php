<?php

namespace App\Enums;

/** Status of a received source or a log step (screen 29). Spanish labels in the SPA. */
enum OnboardingStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
