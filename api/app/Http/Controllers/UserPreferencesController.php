<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET/PATCH /api/v1/me/preferences` (ClickUp 1dfd3mc4zpk): the per-person
 * switch of the «N evaluaciones hoy» total in Psychopedagogy's Inicio. The
 * Inicio («Ocultar» / «Mostrar las evaluaciones de hoy») and the Calendar's
 * filters read and write the same value, stored on the user so it follows the
 * person across computers. Only the caller's own row is ever read or written.
 */
class UserPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user()->show_today_assessments_total);
    }

    public function update(UpdateUserPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['show_today_assessments_total' => $request->boolean('show_today_assessments_total')])->save();

        return $this->respond($user->show_today_assessments_total);
    }

    protected function respond(?bool $showTodayAssessmentsTotal): JsonResponse
    {
        return response()->json(['data' => [
            // A row created before the column existed reads as on (its default).
            'show_today_assessments_total' => $showTodayAssessmentsTotal ?? true,
        ]]);
    }
}
