<?php

namespace App\Enums;

use App\Models\Comment;

/**
 * The four presets of an observation's «Visible para» selector (ClickUp
 * 86e3qkwnv), as a single value for the endpoints that create observations on
 * the user's behalf: a session note and a sheet fragment (screen 10,
 * P10-DEC-10). Maps to the Comment columns that enforce it.
 */
enum ObservationScope: string
{
    case Everyone = 'everyone';
    case DirectionAndPsychopedagogy = 'direction_and_psychopedagogy';
    case PsychopedagogyOnly = 'psychopedagogue';
    case AuthorOnly = 'author_only';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }

    /**
     * @return array{visible_to: list<string>|null, author_only: bool}
     */
    public function commentAttributes(): array
    {
        return match ($this) {
            self::Everyone => ['visible_to' => null, 'author_only' => false],
            self::DirectionAndPsychopedagogy => ['visible_to' => Comment::SCOPE_DIRECTION_AND_PSYCHOPEDAGOGY, 'author_only' => false],
            self::PsychopedagogyOnly => ['visible_to' => Comment::SCOPE_PSYCHOPEDAGOGY_ONLY, 'author_only' => false],
            self::AuthorOnly => ['visible_to' => null, 'author_only' => true],
        };
    }
}
