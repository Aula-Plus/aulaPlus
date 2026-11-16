<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndicatorChartRequest;
use App\Services\Indicators\DataCatalog;
use App\Services\Indicators\IndicatorData;
use Illuminate\Http\JsonResponse;

/**
 * Screen 30 «Gráfico de cruces» (P30-GRA-02): each point is a student with
 * both data; the dotted lines are the band cuts the school configured. No
 * order and no score list: the points come in a fixed order unrelated to any
 * value, and the name only goes with the point, for the hover (red line of
 * screen 30). Psicopedagogía and Dirección only (IndicatorChartRequest).
 *
 * A level of progress without the school's equivalence (Primaria) is placed
 * on an axis of the scale's levels, in the official order — never turned
 * into a number (PA-087/PA-088).
 */
class IndicatorChartController extends Controller
{
    public function show(IndicatorChartRequest $request): JsonResponse
    {
        $data = app(IndicatorData::class);
        $catalog = new DataCatalog($data);
        $roster = $data->roster();
        $groupId = $request->validated('group_id');

        $axes = [];
        $values = [];

        foreach (['x', 'y'] as $axis) {
            $type = (string) $request->validated("{$axis}_type");
            $id = (int) $request->validated("{$axis}_id");
            $raw = $data->values($type, $id);
            $numeric = array_filter($raw, static fn (array $datum): bool => $datum['value'] !== null);
            $kind = $numeric === [] && $raw !== [] ? 'level' : 'number';
            $bands = $data->bands($type, $id);

            $axes[$axis] = [
                'type' => $type,
                'id' => $id,
                'name' => $catalog->name($type, $id),
                'kind' => $kind,
                'scale' => $data->scale($type, $id),
                // Every band but the last ends at a cut.
                'cuts' => array_values(array_filter(array_column(array_slice($bands, 0, -1), 'max'), static fn ($max): bool => $max !== null)),
                'bands' => $bands,
                'levels' => $kind === 'level' ? $data->levels($type, $id) : [],
            ];

            $values[$axis] = $kind === 'level'
                ? array_map(static fn (array $datum): array => [
                    'value' => (float) $datum['level']['position'],
                    'label' => $datum['level']['name'],
                    'population' => null,
                ], array_filter($raw, static fn (array $datum): bool => $datum['level'] !== null))
                : array_map(static fn (array $datum): array => [
                    'value' => (float) $datum['value'],
                    'label' => $datum['band'],
                    'population' => $datum['population'] ?? null,
                ], $numeric);
        }

        // Student-id order: unrelated to either value, so nothing is ranked.
        ksort($values['x']);
        $points = [];

        foreach (array_intersect_key($values['x'], $values['y']) as $studentId => $x) {
            $student = $roster[$studentId];

            if ($groupId !== null && $student['group_id'] !== (int) $groupId) {
                continue;
            }

            $points[] = [
                'name' => $student['name'],
                'group_name' => $student['group_name'],
                'x' => $x['value'],
                'x_label' => $x['label'],
                'x_population' => $x['population'],
                'y' => $values['y'][$studentId]['value'],
                'y_label' => $values['y'][$studentId]['label'],
                'y_population' => $values['y'][$studentId]['population'],
            ];
        }

        return response()->json(['data' => [
            'x' => $axes['x'],
            'y' => $axes['y'],
            'points' => $points,
        ]]);
    }
}
