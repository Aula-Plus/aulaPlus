<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCrossingRequest;
use App\Models\Crossing;
use App\Services\Indicators\CrossingEvaluator;
use App\Services\Indicators\DataCatalog;
use App\Services\Indicators\IndicatorData;
use App\Services\Indicators\SubjectGrades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Screen 30 «Cruces» (ClickUp 1dfd3mc4bgp): each crossing is one or two
 * conditions and a reading the school writes; the AI writes nothing
 * (P30-DEC-04). Psicopedagogía and Dirección only (CrossingPolicy).
 *
 * The preview counts per group and never names a student (PO decision 5):
 * «3 alumnos de 5.°A · 1 de 5.°B». Showing the reading on the student file is
 * the second delivery.
 */
class CrossingController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Crossing::class);

        $data = app(IndicatorData::class);
        $catalog = new DataCatalog($data);
        $evaluator = new CrossingEvaluator($data);

        return response()->json([
            'data' => Crossing::query()->orderBy('name')->get()
                ->map(fn (Crossing $crossing): array => $this->present($crossing, $catalog, $evaluator))
                ->values()
                ->all(),
        ]);
    }

    public function store(SaveCrossingRequest $request): JsonResponse
    {
        $crossing = Crossing::create([
            'name' => trim((string) $request->validated('name')),
            'reading' => trim((string) $request->validated('reading')),
            'conditions' => $request->conditions(),
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentOne($crossing)], Response::HTTP_CREATED);
    }

    public function update(SaveCrossingRequest $request, Crossing $crossing): JsonResponse
    {
        $crossing->update([
            'name' => trim((string) $request->validated('name')),
            'reading' => trim((string) $request->validated('reading')),
            'conditions' => $request->conditions(),
            'updated_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->presentOne($crossing->fresh())]);
    }

    public function destroy(Crossing $crossing): Response
    {
        $this->authorize('delete', $crossing);

        $crossing->delete();

        return response()->noContent();
    }

    /**
     * POST /crossings/preview — whom the crossing being edited would reach
     * today, counted per group.
     */
    public function preview(SaveCrossingRequest $request): JsonResponse
    {
        $evaluator = new CrossingEvaluator(app(IndicatorData::class));

        return response()->json(['data' => $evaluator->preview($request->conditions())]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentOne(Crossing $crossing): array
    {
        $data = app(IndicatorData::class);

        return $this->present($crossing, new DataCatalog($data), new CrossingEvaluator($data));
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Crossing $crossing, DataCatalog $catalog, CrossingEvaluator $evaluator): array
    {
        $conditions = $crossing->conditions ?? [];

        return [
            'id' => $crossing->id,
            'name' => $crossing->name,
            'reading' => $crossing->reading,
            'conditions' => array_map(fn (array $condition): array => [
                ...$condition,
                'source' => array_filter([
                    ...$condition['source'],
                    // A subject always says which grades it reads, so the
                    // reading shows the rule that applied.
                    'grades' => SubjectGrades::forSource((string) $condition['source']['type'], $condition['source']['grades'] ?? null),
                    'name' => $catalog->name((string) $condition['source']['type'], (int) $condition['source']['id']),
                ], static fn ($value, string $key): bool => $key !== 'grades' || $value !== null, ARRAY_FILTER_USE_BOTH),
            ], $conditions),
            'preview' => $evaluator->preview($conditions),
            'updated_at' => $crossing->updated_at?->toIso8601String(),
        ];
    }
}
