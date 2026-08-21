<?php

namespace App\Http\Controllers;

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
        $students = Student::query()
            ->visibleTo($request->user())
            ->with('groups')
            ->latest()
            ->get();

        return StudentResource::collection($students);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->safe()->except(['group_id', 'school_year']));

        if ($request->filled('group_id')) {
            $student->groups()->attach($request->input('group_id'), [
                'school_year' => $request->input('school_year'),
            ]);
        }

        return (new StudentResource($student->load('groups')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student): StudentResource
    {
        $this->authorize('view', $student);

        return new StudentResource($student->load('groups'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->safe()->except(['group_id', 'school_year']));

        if ($request->filled('group_id')) {
            $student->groups()->wherePivot('school_year', $request->input('school_year'))->detach();
            $student->groups()->attach($request->input('group_id'), [
                'school_year' => $request->input('school_year'),
            ]);
        }

        return new StudentResource($student->load('groups'));
    }

    public function destroy(Student $student): Response
    {
        $this->authorize('delete', $student);

        $student->delete();

        return response()->noContent();
    }
}
