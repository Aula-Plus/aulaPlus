<?php

namespace App\Http\Controllers;

use App\Enums\ScreeningFrequency;
use App\Http\Requests\StoreScreeningTestTypeRequest;
use App\Http\Requests\UpdateScreeningTestTypeRequest;
use App\Http\Resources\ScreeningTestTypeResource;
use App\Models\ScreeningTestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screening catalog (docs/prompts/11-pruebas-de-sondeo.md §1, screen 08). The
 * resource carries the design in force and the previous one (if any) so the
 * frontend can list screenings and show the last change in one call.
 */
class ScreeningTestTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ScreeningTestType::class);

        // SchoolScope already constrains this to the current tenant. Eager-load
        // the designs that rule or ruled so the resource resolves each type's
        // in-force and previous design from memory (see
        // ScreeningTestType::ruledDesigns) instead of one query per type.
        $types = ScreeningTestType::query()
            ->with(['designs' => fn ($query) => $query
                ->where('approved', true)
                ->whereNull('reverted_at')
                ->with('createdBy:id,name')])
            ->orderByDesc('id')
            ->get();

        return ScreeningTestTypeResource::collection($types);
    }

    public function store(StoreScreeningTestTypeRequest $request): JsonResponse
    {
        $type = ScreeningTestType::create([
            'name' => $request->validated('name'),
            'area' => $request->validated('area'),
            'levels' => $request->validated('levels'),
            'frequency' => $request->validated('frequency'),
            'frequency_years' => $this->frequencyYears($request->validated()),
            'teacher_visible' => $request->validated('teacher_visible') ?? false,
            'active' => $request->validated('active') ?? true,
            'created_by_id' => $request->user()->id,
        ]);

        return (new ScreeningTestTypeResource($type))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Edit the general data (screen 08). Renaming keeps the same record, so
     * applications and results stay linked and the audit log keeps the old
     * name.
     */
    public function update(UpdateScreeningTestTypeRequest $request, ScreeningTestType $type): ScreeningTestTypeResource
    {
        $data = $request->validated();

        if (array_key_exists('frequency', $data) || array_key_exists('frequency_years', $data)) {
            $data['frequency'] = array_key_exists('frequency', $data) ? $data['frequency'] : $type->frequency?->value;
            $data['frequency_years'] = $this->frequencyYears($data);
        }

        $type->update($data);

        return new ScreeningTestTypeResource($type->fresh()->load([
            'designs' => fn ($query) => $query->where('approved', true)->whereNull('reverted_at')->with('createdBy:id,name'),
        ]));
    }

    /**
     * "Cada cuántos años" only applies to the every-few-years frequency.
     *
     * @param  array<string, mixed>  $data
     */
    protected function frequencyYears(array $data): ?int
    {
        return ($data['frequency'] ?? null) === ScreeningFrequency::EveryFewYears->value
            ? (int) $data['frequency_years']
            : null;
    }
}
