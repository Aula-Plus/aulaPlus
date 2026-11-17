<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\GroupWriteWindow;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\Student;
use App\Models\Unit;
use App\Models\UnitObjective;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\StudentFileHistory;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `group.writable` — screen 26 «Año y calendario» (ClickUp 1dfd3mc4bh7): the
 * one gate in front of every group-data write route. It finds the group the
 * request writes to (from the route's bound record, or `group_id` in the body
 * for a create) and asks GroupPolicy::writeInYear, which rejects writes to a
 * group of a closed year unless direction enabled loading in it.
 *
 * It covers the group's data and, since Eitán's answer of 17/11, its
 * structure too (the group itself, its teachers, its curricular offer and its
 * level), and its roster: enrolling a student in a group, or moving them to
 * another group of that year.
 *
 * It only ever denies: when no group can be resolved (e.g. a record from
 * another school, hidden by the tenant scope) it lets the request through so
 * the endpoint's own Policy answers as usual (404/403).
 *
 * A write that goes through only because direction enabled loading in the
 * group leaves one row in the audit trail on that enablement: who (the
 * user), when, on which group and route — the why is the enablement's
 * reason. The record itself is audited as usual where its model is.
 */
class EnsureGroupIsWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $group = $this->groupFor($request);

        if ($group === null || $request->user() === null) {
            return $next($request);
        }

        $decision = Gate::forUser($request->user())->inspect('writeInYear', $group);
        if ($decision->denied()) {
            return response()->json([
                'message' => $decision->message(),
                'code' => $decision->code(),
            ], $decision->status() ?? 403);
        }

        $calendar = app(SchoolYearCalendar::class);
        $window = $calendar->isInClosedYear($group) ? $calendar->activeWindow($group) : null;

        $response = $next($request);

        if ($window !== null && $response->isSuccessful()) {
            $this->recordUse($request, $window, $group);
        }

        return $response;
    }

    private function recordUse(Request $request, GroupWriteWindow $window, Group $group): void
    {
        $base = StudentFileHistory::baseAttributes($window, AuditAction::Updated);

        AuditLog::create([...$base, 'changes' => [
            'loaded_under_window' => [
                'group_id' => $group->id,
                'method' => $request->method(),
                'route' => $request->route()?->uri(),
            ],
        ]]);
    }

    private function groupFor(Request $request): ?Group
    {
        $route = $request->route();

        $group = $this->bound($route?->parameter('group'), Group::class);
        if ($group instanceof Group) {
            return $group;
        }

        // Most specific first: a class session's objective belongs to the session's group.
        $resolvers = [
            'classSession' => [ClassSession::class, fn (ClassSession $session) => $session->group],
            'assessment' => [Assessment::class, fn (Assessment $assessment) => $assessment->group],
            'annualPlan' => [AnnualPlan::class, fn (AnnualPlan $plan) => $plan->group],
            'unit' => [Unit::class, fn (Unit $unit) => $unit->annualPlan?->group],
            'objective' => [UnitObjective::class, fn (UnitObjective $objective) => $objective->unit?->annualPlan?->group],
            'application' => [ScreeningTestApplication::class, fn (ScreeningTestApplication $application) => $application->group],
            'result' => [ScreeningTestResult::class, fn (ScreeningTestResult $result) => $result->application?->group],
            'ai_proposal' => [AIProposal::class, fn (AIProposal $proposal) => $proposal->group],
        ];

        foreach ($resolvers as $parameter => [$class, $resolve]) {
            $record = $this->bound($route?->parameter($parameter), $class);
            if ($record !== null) {
                return $resolve($record);
            }
        }

        if ($request->filled('group_id') && is_numeric($request->input('group_id'))) {
            $student = $this->bound($route?->parameter('student'), Student::class);
            // Editing a student re-sends their current group: only a change of
            // group for that year writes to the group's roster.
            if ($student instanceof Student && is_numeric($request->input('school_year'))
                && $student->groupForYear((int) $request->input('school_year'))?->id === (int) $request->input('group_id')) {
                return null;
            }

            return Group::query()->find((int) $request->input('group_id'));
        }

        // E.g. turning an accommodation off for one assessment of the group.
        if ($request->filled('assessment_id') && is_numeric($request->input('assessment_id'))) {
            return Assessment::query()->find((int) $request->input('assessment_id'))?->group;
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function bound(mixed $value, string $class): ?Model
    {
        if ($value instanceof $class) {
            return $value;
        }

        return is_numeric($value) ? $class::query()->find((int) $value) : null;
    }
}
