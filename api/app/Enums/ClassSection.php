<?php

namespace App\Enums;

/**
 * The sections of a class the AI proposes and the teacher decides on one by
 * one (screen 15 «Propuesta de clase», ClickUp 86e3jujh0): opening, central
 * activity, closing, materials and how the class is assessed.
 */
enum ClassSection: string
{
    case Opening = 'opening';
    case CentralActivity = 'central_activity';
    case Closing = 'closing';
    case Materials = 'materials';
    case Assessment = 'assessment';

    /** Sections that take class time (the others do not carry minutes). */
    public function isTimed(): bool
    {
        return in_array($this, [self::Opening, self::CentralActivity, self::Closing], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $section) => $section->value, self::cases());
    }
}
