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

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Group::query()->with('teacher');

        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            $query->where('teacher_id', $user->id);
        }

        return GroupResource::collection($query->latest()->get());
    }

    public function store(StoreGroupRequest $request): JsonResponse
    {
        $group = Group::create($request->validated());

        return (new GroupResource($group->load('teacher')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Group $group): GroupResource
    {
        $this->authorize('view', $group);

        return new GroupResource($group->load('teacher'));
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        $group->update($request->validated());

        return new GroupResource($group->load('teacher'));
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $group->delete();

        return response()->json(null, 204);
    }
}
