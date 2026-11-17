<?php

namespace App\Services\Indicators;

use App\Enums\CrossingOperator;
use App\Models\Crossing;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * A crossing's band condition names the band it reads (P30-CRU-02), so the
 * bands of an indicator or a screening cannot lose a name a crossing uses:
 * the crossing would silently stop matching anyone. Editing the indicator,
 * saving a new screening design or reverting to the previous one is refused
 * (422) until the crossing changes first.
 *
 * The 422 carries `bands` (which bands, English) and `used_by_crossings`
 * (the crossings' names, one per message) so the screen can say, in
 * Spanish, which crossing to change.
 */
class CrossingBandUsage
{
    /**
     * The crossings with a band condition on this datum whose band is not
     * among `$keptNames`, with the bands they would lose.
     *
     * @param  list<string>  $keptNames
     * @return Collection<int, array{name: string, bands: list<string>}>
     */
    public static function losing(string $type, int $id, array $keptNames): Collection
    {
        return Crossing::query()->orderBy('name')->get()
            ->map(function (Crossing $crossing) use ($type, $id, $keptNames): array {
                $bands = collect($crossing->conditions ?? [])
                    ->filter(fn (array $condition): bool => ($condition['source']['type'] ?? null) === $type
                        && (int) ($condition['source']['id'] ?? 0) === $id
                        && ($condition['operator'] ?? null) === CrossingOperator::Band->value)
                    ->map(fn (array $condition): string => (string) $condition['value'])
                    ->reject(fn (string $band): bool => in_array($band, $keptNames, true))
                    ->unique()
                    ->values()
                    ->all();

                return ['name' => (string) $crossing->name, 'bands' => $bands];
            })
            ->filter(fn (array $crossing): bool => $crossing['bands'] !== [])
            ->values();
    }

    /**
     * Adds the 422 errors when a crossing would lose a band.
     *
     * @param  list<string>  $keptNames
     */
    public static function validate(Validator $validator, string $type, int $id, array $keptNames): void
    {
        foreach (self::messages($type, $id, $keptNames) as $key => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($key, $message);
            }
        }
    }

    /**
     * The same errors as a ValidationException messages array, for a
     * controller action without a form request (reverting a design).
     *
     * @param  list<string>  $keptNames
     * @return array<string, list<string>>
     */
    public static function messages(string $type, int $id, array $keptNames): array
    {
        $losing = self::losing($type, $id, $keptNames);

        if ($losing->isEmpty()) {
            return [];
        }

        return [
            'bands' => ['A band used by a crossing cannot be removed or renamed: '.$losing->pluck('bands')->flatten()->unique()->implode(', ').'. Change the crossing first.'],
            'used_by_crossings' => $losing->pluck('name')->all(),
        ];
    }
}
