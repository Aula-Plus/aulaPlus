<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ScreeningColor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The band rules of screen 08, shared by saving a design and previewing its
 * impact: 2 to 5 bands, each with a name, a colour, the score it goes up to
 * and a mandatory meaning. Every band but the last needs a `max`, and the
 * `max` values must go strictly up.
 */
trait ValidatesScreeningBands
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function bandRules(): array
    {
        return [
            'bands' => ['required', 'array', 'min:2', 'max:5'],
            'bands.*' => ['required', 'array:name,color,max,meaning'],
            'bands.*.name' => ['required', 'string', 'max:60'],
            'bands.*.color' => ['required', Rule::enum(ScreeningColor::class)],
            'bands.*.max' => ['nullable', 'numeric'],
            'bands.*.meaning' => ['required', 'string', 'max:1000'],
        ];
    }

    protected function validateBandOrder(Validator $validator): void
    {
        $bands = $this->input('bands');

        if (! is_array($bands) || $validator->errors()->isNotEmpty()) {
            return;
        }

        $bands = array_values($bands);
        $last = count($bands) - 1;
        $previous = null;

        foreach ($bands as $index => $band) {
            $max = $band['max'] ?? null;

            if ($max === null) {
                if ($index !== $last) {
                    $validator->errors()->add("bands.{$index}.max", 'Every band but the last needs the score it goes up to.');
                }

                continue;
            }

            if ($previous !== null && (float) $max <= $previous) {
                $validator->errors()->add("bands.{$index}.max", 'Each band must go up to a higher score than the previous one.');
            }

            $previous = (float) $max;
        }
    }

    /**
     * The validated bands, normalised (numeric `max`, trimmed text).
     *
     * @return list<array{name: string, color: string, max: float|null, meaning: string}>
     */
    public function bands(): array
    {
        return array_values(array_map(static fn (array $band): array => [
            'name' => trim((string) $band['name']),
            'color' => (string) $band['color'],
            'max' => isset($band['max']) ? (float) $band['max'] : null,
            'meaning' => trim((string) $band['meaning']),
        ], $this->validated('bands')));
    }
}
