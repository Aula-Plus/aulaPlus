<?php

namespace App\Support;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Support\Str;

/**
 * Finds student names inside free text, ignoring case and accents ("Lucia"
 * matches "Lucía") and splitting words on anything that is not a letter
 * ("Pérez-Gómez" → "perez", "gomez"; "Pereyra," → "pereyra"). Combining
 * accents count as part of the word, so decomposed text matches too.
 *
 * Two levels, because many first names and surnames are ordinary Spanish
 * words (Clara, Luz, Paz, Sol, Blanca, Franco, León, Luna…):
 *
 * - A *full name* is two parts of the same student's `full_name` written next
 *   to each other ("Lucía Pereyra", "Pereyra Lucía", "María José"). Checked
 *   against every student of the school: a coincidence is very unlikely.
 * - A *name part* is any single part of the name of a student of the group.
 *   Stricter, so it is only used where a false negative would leak a name
 *   (text about to be sent to a third-party API, CLAUDE.md rule 11) and is
 *   limited to the group's own students to keep false positives rare.
 *
 * Name parts shorter than 3 letters and generic particles ("del", "san"…)
 * are ignored, in names and in the text alike ("María del Carmen" still
 * matches "María Carmen").
 */
class StudentNameMatcher
{
    protected const MIN_PART_LENGTH = 3;

    /** Name fragments too generic to prove a student is being named. */
    protected const STOPWORDS = ['del', 'las', 'los', 'van', 'von', 'dos', 'san', 'que'];

    /**
     * @param  array<string, true>  $fullNamePairs  "part part" keys of every student of the school
     * @param  array<string, true>  $groupNameParts  single name parts of the group's students
     */
    protected function __construct(
        protected array $fullNamePairs,
        protected array $groupNameParts,
    ) {}

    /**
     * Matcher for the current school (tenant scope) and the given group.
     */
    public static function forGroup(Group $group): self
    {
        $pairs = [];

        foreach (Student::query()->pluck('full_name') as $fullName) {
            $parts = array_values(array_unique(array_column(self::words((string) $fullName), 0)));

            foreach ($parts as $i => $first) {
                foreach ($parts as $j => $second) {
                    if ($i !== $j) {
                        $pairs["{$first} {$second}"] = true;
                    }
                }
            }
        }

        $groupParts = [];

        foreach ($group->students()->pluck('full_name') as $fullName) {
            foreach (self::words((string) $fullName) as [$part]) {
                $groupParts[$part] = true;
            }
        }

        return new self($pairs, $groupParts);
    }

    /** True when the text contains the full name of any student of the school. */
    public function containsFullName(string $text): bool
    {
        return $this->matches($text, includeParts: false) !== [];
    }

    /**
     * True when the text contains any name part of a student of the group, or
     * the full name of any student of the school.
     */
    public function containsAnyName(string $text): bool
    {
        return $this->matches($text, includeParts: true) !== [];
    }

    /**
     * The text with every word {@see containsAnyName()} would flag replaced by
     * "…", so it can be shown to say *which* text to fix without repeating a
     * student's name.
     */
    public function redact(string $text): string
    {
        // Replace from the end so earlier byte offsets stay valid.
        foreach (array_reverse($this->matches($text, includeParts: true)) as [$offset, $length]) {
            $text = substr_replace($text, '…', $offset, $length);
        }

        return $text;
    }

    /**
     * Byte ranges of the matched words, in order and without overlaps.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function matches(string $text, bool $includeParts): array
    {
        $words = self::words($text);
        $flagged = [];

        foreach ($words as $k => [$word]) {
            if ($includeParts && isset($this->groupNameParts[$word])) {
                $flagged[$k] = true;
            }

            if (isset($words[$k + 1]) && isset($this->fullNamePairs["{$word} {$words[$k + 1][0]}"])) {
                $flagged[$k] = true;
                $flagged[$k + 1] = true;
            }
        }

        ksort($flagged);

        return array_map(fn (int $k): array => [$words[$k][1], $words[$k][2]], array_keys($flagged));
    }

    /**
     * Significant words of a text: normalised (lower case, no accents), with
     * their byte offset and byte length in the original string.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    protected static function words(string $text): array
    {
        preg_match_all('/[\pL\pM]+/u', $text, $found, PREG_OFFSET_CAPTURE);

        $words = [];

        foreach ($found[0] as [$raw, $offset]) {
            $word = mb_strtolower(Str::ascii($raw));

            if (mb_strlen($word) >= self::MIN_PART_LENGTH && ! in_array($word, self::STOPWORDS, true)) {
                $words[] = [$word, $offset, strlen($raw)];
            }
        }

        return $words;
    }
}
