<?php

namespace App\Http\Controllers;

use App\Http\Requests\Resources\StoreResourceRequest;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceExclusion;
use App\Models\SchoolResource;
use App\Services\SchoolYears\SchoolYearCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Recursos disponibles» (screen 23 P23-REC-02 and the group's card «Del
 * colegio y del grupo», PA-085 — ClickUp 1dfd3mc4bh4). Dirección loads the
 * school's resources; in each group, its teachers (and Dirección) uncheck the
 * ones the group does not have at hand and add the group's own. Psicopedagogía
 * reads. No student data.
 */
class ResourceController extends Controller
{
    public function schoolIndex(): JsonResponse
    {
        $this->authorize('viewAny', SchoolResource::class);

        return response()->json(['data' => SchoolResource::query()->orderBy('position')->orderBy('id')->get(['id', 'name'])]);
    }

    public function schoolStore(StoreResourceRequest $request): JsonResponse
    {
        $this->authorize('create', SchoolResource::class);

        $resource = SchoolResource::create([
            'name' => trim($request->validated('name')),
            'position' => (int) SchoolResource::query()->max('position') + 1,
        ]);

        return response()->json(['data' => ['id' => $resource->id, 'name' => $resource->name]], 201);
    }

    public function schoolDestroy(SchoolResource $resource): JsonResponse
    {
        $this->authorize('delete', $resource);

        $resource->delete();

        return response()->json(null, 204);
    }

    public function groupIndex(Request $request, Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return response()->json(['data' => $this->groupPayload($request, $group)]);
    }

    /** Check or uncheck one school resource for the group. */
    public function setAvailability(Request $request, Group $group, SchoolResource $resource): JsonResponse
    {
        $this->authorize('manageResources', $group);
        abort_unless($resource->school_id === $group->school_id, 404);
        $available = $request->validate(['available' => ['required', 'boolean']])['available'];

        if ($available) {
            GroupResourceExclusion::query()->where('group_id', $group->id)->where('school_resource_id', $resource->id)->delete();
        } else {
            GroupResourceExclusion::query()->firstOrCreate(['group_id' => $group->id, 'school_resource_id' => $resource->id]);
        }

        return response()->json(['data' => $this->groupPayload($request, $group)]);
    }

    public function groupStore(StoreResourceRequest $request, Group $group): JsonResponse
    {
        $this->authorize('manageResources', $group);

        GroupResource::create([
            'group_id' => $group->id,
            'name' => trim($request->validated('name')),
            'created_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->groupPayload($request, $group)], 201);
    }

    public function groupDestroy(Request $request, Group $group, GroupResource $groupResource): JsonResponse
    {
        $this->authorize('manageResources', $group);
        abort_unless($groupResource->group_id === $group->id, 404);

        $groupResource->delete();

        return response()->json(['data' => $this->groupPayload($request, $group)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupPayload(Request $request, Group $group): array
    {
        $excluded = GroupResourceExclusion::query()->where('group_id', $group->id)->pluck('school_resource_id');

        // A group of a closed year without a loading window is read-only
        // (screen 26). UX hint only: `group.writable` rejects the writes.
        $calendar = app(SchoolYearCalendar::class);
        $readOnly = $calendar->isInClosedYear($group) && $calendar->activeWindow($group) === null;

        return [
            'can_manage' => ! $readOnly && $request->user()->can('manageResources', $group),
            'school' => SchoolResource::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (SchoolResource $resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'available' => ! $excluded->contains($resource->id),
                ])->values(),
            'group' => GroupResource::query()->with('createdBy:id,name')->where('group_id', $group->id)->orderBy('id')->get()
                ->map(fn (GroupResource $resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'created_by_name' => $resource->createdBy?->name,
                ])->values(),
        ];
    }
}
