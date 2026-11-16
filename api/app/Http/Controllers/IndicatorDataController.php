<?php

namespace App\Http\Controllers;

use App\Models\Indicator;
use App\Services\Indicators\DataCatalog;
use App\Services\Indicators\IndicatorData;
use Illuminate\Http\JsonResponse;

/**
 * Screen 30 «Datos» (P30-DAT-02, ClickUp 1dfd3mc4bgp): the data available to
 * build indicators and crossings, with their original scale and coverage,
 * plus this year's assessments (a crossing can be on the grade of one) and
 * this year's groups. Psicopedagogía and Dirección only (IndicatorPolicy).
 * Aggregate only: no student is named here.
 */
class IndicatorDataController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Indicator::class);

        $data = app(IndicatorData::class);
        $catalog = new DataCatalog($data);
        $roster = $data->roster();

        return response()->json(['data' => [
            'school_year' => $data->schoolYear(),
            'roster_count' => count($roster),
            'groups' => collect($roster)
                ->map(fn (array $student): array => ['id' => $student['group_id'], 'name' => $student['group_name']])
                ->unique('id')
                ->sortBy('name', SORT_NATURAL)
                ->values()
                ->all(),
            'sources' => $catalog->sources(),
            'assessments' => $catalog->assessments(),
        ]]);
    }
}
