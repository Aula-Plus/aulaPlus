<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Accommodation;
use App\Models\Alert;
use App\Models\Barrier;
use App\Models\Group;
use App\Models\ScheduledFollowUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = Group::query()
            ->visibleTo($request->user())
            ->with('teachers')
            ->latest()
            ->get();

        $counts = $this->activeTrackingCounts($groups->pluck('id')->all());

        $groups->each(function (Group $group) use ($counts): void {
            $group->active_tracking_count = $counts[$group->id] ?? 0;
        });

        return GroupResource::collection($groups);
    }

    /**
     * Count, per group, how many of its students have *active tracking* — i.e.
     * at least one of: an effective Accommodation, an active Barrier, an
     * unresolved Alert, or an unresolved ScheduledFollowUp
     * (docs/prompts/22-grupos-listado.md §1). Aggregate only: a name is never
     * exposed, only the count.
     *
     * Computed in four queries total (one per source table), never one per
     * group — each query returns the distinct (group_id, student_id) pairs
     * that qualify, and the sets are unioned in PHP so a student who matches
     * on more than one source is counted once, not summed.
     *
     * @param  list<int>  $groupIds
     * @return array<int, int> group_id => distinct student count
     */
    private function activeTrackingCounts(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        // group_id => set of student_ids (keys) with active tracking.
        $studentsByGroup = [];

        $union = function (Builder $query) use ($groupIds, &$studentsByGroup): void {
            foreach ($this->trackingPairs($query, $groupIds) as $pair) {
                $studentsByGroup[$pair->group_id][$pair->student_id] = true;
            }
        };

        // An Accommodation counts when it is effective: replicate
        // Accommodation::isEffective() in SQL (active AND, if it requires
        // external approval, that approval was granted) rather than hydrating
        // and calling the method per row, which would reintroduce N+1.
        $union(Accommodation::query()
            ->where('accommodations.active', true)
            ->where(fn (Builder $q) => $q
                ->where('accommodations.requires_external_approval', false)
                ->orWhere('accommodations.approved', true)));

        $union(Barrier::query()->where('barriers.active', true));

        $union(Alert::query()->where('alerts.resolved', false));

        $union(ScheduledFollowUp::query()->where('scheduled_follow_ups.resolved', false));

        return array_map('count', $studentsByGroup);
    }

    /**
     * The distinct (group_id, student_id) pairs, restricted to $groupIds, for
     * the students matching the given (already-filtered) tracking query. The
     * model's global SchoolScope and any soft-delete scope stay applied, so
     * tenant isolation and trashed rows are handled automatically.
     *
     * @param  list<int>  $groupIds
     * @return Collection<int, object{group_id: int, student_id: int}>
     */
    private function trackingPairs(Builder $query, array $groupIds): Collection
    {
        $table = $query->getModel()->getTable();

        return $query
            ->join('group_student', 'group_student.student_id', '=', "{$table}.student_id")
            ->whereIn('group_student.group_id', $groupIds)
            ->distinct()
            ->get(['group_student.group_id as group_id', 'group_student.student_id as student_id'])
            ->map(fn ($row) => (object) [
                'group_id' => (int) $row->group_id,
                'student_id' => (int) $row->student_id,
            ]);
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create($request->safe()->except('teacher_ids'));

        if ($request->filled('teacher_ids')) {
            $group->teachers()->sync($request->input('teacher_ids'));
        }

        return (new GroupResource($group->load('teachers')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Group $group): GroupResource
    {
        $this->authorize('view', $group);

        return new GroupResource($group->load('teachers'));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $group->update($request->safe()->except('teacher_ids'));

        if ($request->has('teacher_ids')) {
            $group->teachers()->sync($request->input('teacher_ids', []));
        }

        return new GroupResource($group->load('teachers'));
    }

    public function destroy(Group $group): Response
    {
        $this->authorize('delete', $group);

        $group->delete();

        return response()->noContent();
    }
}
