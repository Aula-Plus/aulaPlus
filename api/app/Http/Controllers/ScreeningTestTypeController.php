<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScreeningTestTypeRequest;
use App\Http\Resources\ScreeningTestTypeResource;
use App\Models\ScreeningTestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screening-test type catalog (docs/prompts/11-pruebas-de-sondeo.md §1). The
 * type resource carries its current approved design (if any) so the frontend
 * can list types with their in-force cutoffs in one call.
 */
class ScreeningTestTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ScreeningTestType::class);

        // SchoolScope already constrains this to the current tenant. Eager-load
        // the approved designs so the resource resolves each type's in-force
        // design from memory (see ScreeningTestType::currentApprovedDesign)
        // instead of firing one query per type.
        $types = ScreeningTestType::query()
            ->with(['designs' => fn ($query) => $query->where('approved', true)])
            ->orderByDesc('id')
            ->get();

        return ScreeningTestTypeResource::collection($types);
    }

    public function store(StoreScreeningTestTypeRequest $request): JsonResponse
    {
        $type = ScreeningTestType::create([
            'name' => $request->validated('name'),
            'active' => $request->validated('active') ?? true,
            'created_by_id' => $request->user()->id,
        ]);

        return (new ScreeningTestTypeResource($type))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
