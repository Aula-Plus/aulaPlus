<?php

namespace App\Http\Controllers;

use App\Models\EducationLevel;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/education-levels — the level list with the grades each one
 * suggests (ClickUp 86e3t4qjh), for the group form. Global reference data,
 * the same for every school; any authenticated staff member reads it.
 * `groups_without_level` is how many of the school's groups the viewer can see
 * still have no level ("N grupos sin nivel").
 */
class EducationLevelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $levels = EducationLevel::query()->with('grades')->orderBy('position')->get();

        return response()->json([
            'data' => $levels->map(fn (EducationLevel $level): array => [
                'id' => $level->id,
                'key' => $level->key,
                'label' => $level->label,
                'grades' => $level->grades->map(fn ($grade): array => [
                    'id' => $grade->id,
                    'key' => $grade->key,
                    'label' => $grade->label,
                ])->all(),
            ])->all(),
            'groups_without_level' => Group::query()
                ->visibleTo($request->user())
                ->whereNull('education_level_id')
                ->count(),
        ]);
    }
}
