<?php

namespace App\Http\Controllers;

use App\Enums\IndicatorSourceType;
use App\Http\Requests\SaveIndicatorRequest;
use App\Models\Crossing;
use App\Models\Indicator;
use App\Services\Indicators\DataCatalog;
use App\Services\Indicators\IndicatorCalculator;
use App\Services\Indicators\IndicatorData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Screen 30 «Indicadores» (ClickUp 1dfd3mc4bgp): the school's indicators,
 * created and edited by Psicopedagogía and Dirección (IndicatorPolicy), and
 * «Ver cómo queda con los datos de hoy».
 *
 * Every summary is aggregate — how many students have a value and how many
 * fall in each band — and never names a student: an indicator never orders
 * students (red line of screen 30). The per-student value is the second
 * delivery (student file and group profile).
 */
class IndicatorController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);

        $data = app(IndicatorData::class);
        $catalog = new DataCatalog($data);

        return response()->json([
            'data' => Indicator::query()->orderBy('name')->get()
                ->map(fn (Indicator $indicator): array => $this->present($indicator, $data, $catalog))
                ->values()
                ->all(),
        ]);
    }

    public function store(SaveIndicatorRequest $request): JsonResponse
    {
        $indicator = Indicator::create([
            ...$request->indicatorData(),
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);

        $data = app(IndicatorData::class);

        return response()->json(['data' => $this->present($indicator, $data, new DataCatalog($data))], Response::HTTP_CREATED);
    }

    public function update(SaveIndicatorRequest $request, Indicator $indicator): JsonResponse
    {
        $indicator->update([
            ...$request->indicatorData(),
            'updated_by_id' => $request->user()->id,
        ]);

        $data = app(IndicatorData::class);

        return response()->json(['data' => $this->present($indicator->fresh(), $data, new DataCatalog($data))]);
    }

    /**
     * An indicator another indicator or a crossing uses cannot be deleted
     * until they stop using it.
     */
    public function destroy(Indicator $indicator): Response
    {
        $this->authorize('delete', $indicator);

        $usedByIndicator = Indicator::query()->whereKeyNot($indicator->id)->get()
            ->contains(fn (Indicator $other): bool => collect($other->inputs ?? [])
                ->contains(fn (array $input): bool => ($input['type'] ?? null) === IndicatorSourceType::Indicator->value
                    && (int) $input['id'] === $indicator->id));
        $usedByCrossing = Crossing::query()->get()
            ->contains(fn (Crossing $crossing): bool => collect($crossing->conditions ?? [])
                ->contains(fn (array $condition): bool => ($condition['source']['type'] ?? null) === IndicatorSourceType::Indicator->value
                    && (int) $condition['source']['id'] === $indicator->id));

        if ($usedByIndicator || $usedByCrossing) {
            throw ValidationException::withMessages(['indicator' => 'The indicator is used by another indicator or a crossing.']);
        }

        $indicator->delete();

        return response()->noContent();
    }

    /**
     * POST /indicators/preview — how the indicator being edited reads with
     * today's data, before saving it.
     */
    public function preview(SaveIndicatorRequest $request): JsonResponse
    {
        $spec = $request->indicatorData();
        $data = app(IndicatorData::class);

        return response()->json(['data' => $this->summary($spec, $data)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Indicator $indicator, IndicatorData $data, DataCatalog $catalog): array
    {
        $spec = IndicatorCalculator::specOf($indicator);

        return [
            'id' => $indicator->id,
            'name' => $indicator->name,
            'calculation' => $indicator->calculation->value,
            'inputs' => array_map(fn (array $input): array => [
                ...$input,
                'name' => $catalog->name($input['type'], $input['id']),
            ], $spec['inputs']),
            'base_100' => $indicator->base_100,
            'z_population' => $indicator->z_population?->value,
            'bands' => $indicator->bands,
            'display_places' => $indicator->display_places ?? [],
            'teacher_visible' => $indicator->teacher_visible,
            'summary' => $this->summary($spec, $data),
            'updated_at' => $indicator->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{roster_count: int, students_with_data: int, bands: list<array{name: string, color: string, count: int}>, populations: list<array{kind: string, label: string|null, size: int}>}
     */
    protected function summary(array $spec, IndicatorData $data): array
    {
        $values = (new IndicatorCalculator($data))->compute($spec);
        $counts = array_count_values(array_filter(array_column($values, 'band')));

        return [
            'roster_count' => count($data->roster()),
            'students_with_data' => count($values),
            'bands' => array_values(array_map(static fn (array $band): array => [
                'name' => (string) $band['name'],
                'color' => (string) $band['color'],
                'count' => (int) ($counts[$band['name']] ?? 0),
            ], $spec['bands'])),
            'populations' => collect($values)->pluck('population')->filter()->unique(fn (array $population): string => $population['kind'].'|'.$population['label'])
                ->sortBy('label')->values()->all(),
        ];
    }
}
