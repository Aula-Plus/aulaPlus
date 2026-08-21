<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Group::query()->with('teachers');

        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            $query->whereHas('teachers', fn ($q) => $q->where('users.id', $user->id));
        }

        return GroupResource::collection($query->latest()->get());
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
