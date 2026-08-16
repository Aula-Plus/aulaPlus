<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StudentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = Student::query()->with('group');

        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            $query->whereHas('group', fn ($q) => $q->where('teacher_id', $user->id));
        }

        return StudentResource::collection($query->latest()->get());
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        return (new StudentResource($student->load('group')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student): StudentResource
    {
        $this->authorize('view', $student);

        return new StudentResource($student->load('group'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        return new StudentResource($student->load('group'));
    }

    public function destroy(Student $student): Response
    {
        $this->authorize('delete', $student);

        $student->delete();

        return response()->noContent();
    }
}
