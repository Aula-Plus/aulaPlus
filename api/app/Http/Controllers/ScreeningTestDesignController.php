<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScreeningTestDesignRequest;
use App\Http\Resources\ScreeningTestDesignResource;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Design authoring (docs/prompts/11-pruebas-de-sondeo.md §1). "Editing" a
 * design means creating a new version: each POST inserts a fresh
 * ScreeningTestDesign row for the type, left `approved = null` (pending
 * director approval). The type keeps the whole history; the version used to
 * compute new colors is the most recent approved one.
 */
class ScreeningTestDesignController extends Controller
{
    public function store(StoreScreeningTestDesignRequest $request, ScreeningTestType $type): JsonResponse
    {
        $design = ScreeningTestDesign::create([
            'screening_test_type_id' => $type->id,
            'cutoff_low' => $request->validated('cutoff_low'),
            'cutoff_high' => $request->validated('cutoff_high'),
            'meaning_red' => $request->validated('meaning_red'),
            'meaning_yellow' => $request->validated('meaning_yellow'),
            'meaning_green' => $request->validated('meaning_green'),
            'created_by_id' => $request->user()->id,
            'approved' => null,
        ]);

        return (new ScreeningTestDesignResource($design))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
