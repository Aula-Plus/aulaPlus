<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCommentTrendSettingsRequest;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The school's recurrence threshold for the comment trend mark: "at least N
 * comments of the same category in D days". Psychopedagogy and direction read
 * it; only direction changes it (SchoolPolicy). Always the caller's own school.
 */
class CommentTrendSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $school = $this->school($request);
        $this->authorize('viewCommentTrendSettings', $school);

        return response()->json(['data' => $this->present($school)]);
    }

    public function update(UpdateCommentTrendSettingsRequest $request): JsonResponse
    {
        $school = $this->school($request);
        $school->update([
            'comment_trend_min_count' => $request->validated('min_count'),
            'comment_trend_days' => $request->validated('days'),
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
