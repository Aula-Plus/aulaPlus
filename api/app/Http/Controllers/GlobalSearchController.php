<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalSearchRequest;
use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;

/**
 * «Buscar en Aula+» (screen 01, P01-DEC-118 / PA-134, ClickUp 1dfd3mc56kr):
 * students, groups, assessments and classes the caller could open, by name
 * or title.
 * Menu sections are matched in the SPA from the same role-filtered navigation
 * as the sidebar.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearch $search): JsonResponse
    {
        return response()->json([
            'data' => $search->for($request->user(), $request->term()),
        ]);
    }
}
