<?php

namespace App\Enums;

/**
 * The kind of control a unit-planner field is. `automatic` fields are filled
 * by Aula+ (resources from the group profile, differentiation from the
 * group's accommodations aggregated by type) — a school can rename or hide
 * them but never add one of its own.
 */
enum PlannerFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Number = 'number';
    case List = 'list';
    case MultipleChoice = 'multiple_choice';
    case Automatic = 'automatic';

    /** Whether the control picks from a list of options. */
    public function hasOptions(): bool
    {
        return $this === self::List || $this === self::MultipleChoice;
    }

    /** @return list<string> the types a school may give a field of its own */
    public static function customValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type !== self::Automatic),
        ));
    }
}
