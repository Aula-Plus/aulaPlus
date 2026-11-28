<?php

namespace App\Http\Requests;

use App\Enums\AIRequestType;
use App\Services\AIInstructions\SchoolAIInstructions;
use Illuminate\Validation\Rule;

/**
 * Validation of the `settings` of a rule of the school's AI layer, per type
 * (screen 24, ClickUp 1dfd3mc4bh6). Shared by the store and update requests.
 */
final class SchoolAIInstructionSettingsRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function for(mixed $type): array
    {
        $type = is_string($type) ? AIRequestType::tryFrom($type) : null;
        $rules = ['settings' => ['sometimes', 'array']];

        if ($type === null) {
            return $rules;
        }

        foreach (SchoolAIInstructions::fields($type) as $field => $kind) {
            $rules["settings.{$field}"] = match (true) {
                is_array($kind) => ['nullable', 'string', Rule::in($kind)],
                $kind === 'bool' => ['nullable', 'boolean'],
                $kind === 'words' => ['nullable', 'string', 'max:'.SchoolAIInstructions::MAX_WORDS],
                default => ['nullable', 'string', 'max:'.SchoolAIInstructions::MAX_TEXT],
            };
        }

        return $rules;
    }
}
