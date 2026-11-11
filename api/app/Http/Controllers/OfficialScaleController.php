<?php

namespace App\Http\Controllers;

use App\Http\Resources\OfficialScaleResource;
use App\Models\School;
use App\Services\ResultScales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/official-scales — screen 24 · «Escalas oficiales»: the scales
 * of the frameworks the school uses, each with its levels in order and its
 * citation. Read-only by design: the scales are loaded by the Aula+ team
 * (OfficialScaleSeeder) and there is deliberately no write route
 * (P24-ESC-01, P24-DEC-03). Direction only (SchoolPolicy::viewOfficialScales).
 */
class OfficialScaleController extends Controller
{
    public function index(Request $request, ResultScales $scales): AnonymousResourceCollection
    {
        $school = School::query()->findOrFail($request->user()->school_id);
        $this->authorize('viewOfficialScales', $school);

        return OfficialScaleResource::collection($scales->scalesForSchool($school));
    }
}
