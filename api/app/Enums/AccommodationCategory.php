<?php

namespace App\Enums;

/**
 * The three categories of pedagogical accommodation the product distinguishes
 * (docs/prompts/18-ajustes-categoria-instancia.md §1), independent of the free
 * `type` description:
 *
 * - Access:   how the task/environment reaches the student (e.g. read-aloud).
 * - Content:  what is taught/asked is adapted.
 * - Criteria: what is scored changes (e.g. "spelling does not count in Spanish").
 *
 * User-facing Spanish labels live in the frontend, per CLAUDE.md.
 */
enum AccommodationCategory: string
{
    case Access = 'access';
    case Content = 'content';
    case Criteria = 'criteria';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
