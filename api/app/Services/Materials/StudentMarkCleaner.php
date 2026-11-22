<?php

namespace App\Services\Materials;

use App\Models\Group;
use App\Support\StudentNameMatcher;

/**
 * «Antes de publicar, Aula+ le saca cualquier marca de alumno: nombre, grupo o
 * ajuste individual» (screen 19 red line, P19-INS-02). Applied to the content
 * of a confirmed assessment before its catalog copy is stored:
 *
 * - any key that carries per-student or accommodation data (an individual
 *   adjustment, a variant for one student…) is dropped whole;
 * - every remaining text has the names of the group's students replaced by
 *   «…» (StudentNameMatcher, the strict mode used before text leaves for a
 *   third party) and the group's own name replaced by «el grupo».
 *
 * What is left is the pedagogical resource, not the case that produced it.
 */
class StudentMarkCleaner
{
    /** Key fragments whose value is about one student or an adjustment. */
    protected const DROPPED_KEY = '/(student|alumno|accommodat|adjust|ajuste|adaptac|adaptation|barrier|barrera|variant|individual)/i';

    protected StudentNameMatcher $names;

    public function __construct(protected Group $group)
    {
        $this->names = StudentNameMatcher::forGroup($group);
    }

    /**
     * @param  array<array-key, mixed>|null  $content
     * @return array<array-key, mixed>|null
     */
    public function clean(?array $content): ?array
    {
        return $content === null ? null : $this->cleanArray($content);
    }

    public function cleanText(string $text): string
    {
        $text = $this->names->redact($text);
        $groupName = trim((string) $this->group->name);

        if ($groupName !== '') {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($groupName, '/').'(?![\p{L}\p{N}])/iu', 'el grupo', $text);
        }

        return $text;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected function cleanArray(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::DROPPED_KEY, $key) === 1) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($item) => $this->cleanArray($item),
                is_string($item) => $this->cleanText($item),
                default => $item,
            };
        }

        return array_is_list($value) ? array_values($clean) : $clean;
    }
}
