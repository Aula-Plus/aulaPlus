<?php

namespace App\Enums;

/**
 * Whether a proposed accommodation is stated in the report (`explicit`),
 * deduced by the AI from it (`inferred`), or added by hand because the AI
 * missed it (`manual`).
 */
enum ProposalBasis: string
{
    case Explicit = 'explicit';
    case Inferred = 'inferred';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function aiValues(): array
    {
        return [self::Explicit->value, self::Inferred->value];
    }
}
