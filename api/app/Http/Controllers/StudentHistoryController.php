<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentHistoryRequest;
use App\Http\Resources\StudentHistoryEntryResource;
use App\Models\Student;
use App\Services\StudentChangeHistory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Change history of a student's file ("historial de cambios", ClickUp
 * 86e3qkwmc): every addition, modification and removal in any editable part
 * of the file — summary, student data, learning profile, documents,
 * accommodations, barriers, observations, reports, follow-ups and alerts.
 * Changes only: reading the file writes nothing.
 *
 * Psychopedagogy and direction only (StudentHistoryRequest →
 * StudentPolicy::viewChangeHistory). Each entry is additionally checked
 * against the visibility rules of the record it is about
 * (StudentChangeHistory) — an observation restricted to psychopedagogy never
 * reaches a director through here.
 *
 * Filters: ?user_id, ?section, ?action (created|updated|deleted), ?from,
 * ?to. Page-based pagination (?page=N, 20 per page) with Laravel's standard
 * `links`/`meta`; `actors` lists the people in the visible history, for the
 * "Persona" filter.
 */
class StudentHistoryController extends Controller
{
    private const PER_PAGE = 20;

    public function show(StudentHistoryRequest $request, Student $student, StudentChangeHistory $history): AnonymousResourceCollection
    {
        $visible = $history->visibleEntries($student, $request->user());
        $filtered = $history->filter($visible, $request->validated());

        $page = max(1, (int) $request->validated('page', 1));
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return StudentHistoryEntryResource::collection($paginator)
            ->additional(['actors' => $history->actors($visible)]);
    }
}
