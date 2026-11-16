<?php

namespace App\Http\Middleware;

use App\Models\AIProposal;
use App\Models\AnnualPlan;
use App\Models\Assessment;
use App\Models\ClassSession;
use App\Models\Group;
use App\Models\ScreeningTestApplication;
use App\Models\ScreeningTestResult;
use App\Models\Unit;
use App\Models\UnitObjective;
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
 * It only ever denies: when no group can be resolved (e.g. a record from
 * another school, hidden by the tenant scope) it lets the request through so
 * the endpoint's own Policy answers as usual (404/403).
 */
class EnsureGroupIsWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $group = $this->groupFor($request);

        if ($group !== null && $request->user() !== null) {
            $decision = Gate::forUser($request->user())->inspect('writeInYear', $group);
            if ($decision->denied()) {
                return response()->json([
                    'message' => $decision->message(),
                    'code' => $decision->code(),
                ], $decision->status() ?? 403);
            }
        }

        return $next($request);
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
            return Group::query()->find((int) $request->input('group_id'));
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
