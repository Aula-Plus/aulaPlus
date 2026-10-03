<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school's recurrence threshold for the comment trend mark: "at least N
 * comments of the same category in D days". Psychopedagogy and direction read
 * it; only direction changes it. Always the caller's own school.
 */
class CommentTrendSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(Role::schoolWideValues()), 403);

        return response()->json(['data' => $this->present($this->school($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Role::Director->value), 403);

        $data = $request->validate([
            'min_count' => ['required', 'integer', 'min:2', 'max:50'],
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $school = $this->school($request);
        $school->update([
            'comment_trend_min_count' => $data['min_count'],
            'comment_trend_days' => $data['days'],
        ]);

        return response()->json(['data' => $this->present($school)]);
    }

    protected function school(Request $request): School
    {
        return School::query()->findOrFail($request->user()->school_id);
    }

    /**
     * @return array{min_count: int, days: int}
     */
    protected function present(School $school): array
    {
        return ['min_count' => $school->comment_trend_min_count, 'days' => $school->comment_trend_days];
    }
}
