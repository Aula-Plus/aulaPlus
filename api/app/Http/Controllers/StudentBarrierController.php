<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStudentBarrierRequest;
use App\Models\Accommodation;
use App\Models\Barrier;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

/**
 * A student's learning barriers, for the «Nuevo ajuste» of «Ajustes y PTP»
 * (screen 06, ClickUp 1dfd3mc4bgc): psychopedagogy picks one of the student's
 * barriers, or registers a new one, and the accommodation is then linked to it
 * through the existing barrier–accommodation endpoint. Psychopedagogy only:
 * the student file keeps showing barriers to whoever it already did.
 */
class StudentBarrierController extends Controller
{
    public function index(Student $student): JsonResponse
    {
        $this->authorize('viewInbox', Accommodation::class);

        return response()->json([
            'data' => $student->barriers()
                ->where('active', true)
                ->orderBy('id')
                ->get(['id', 'description', 'coping_strategy'])
                ->map(fn (Barrier $barrier): array => [
                    'id' => $barrier->id,
                    'description' => $barrier->description,
                    'coping_strategy' => $barrier->coping_strategy,
                ]),
        ]);
    }

    public function store(StoreStudentBarrierRequest $request, Student $student): JsonResponse
    {
        $barrier = $student->barriers()->create([
            ...$request->validated(),
            'active' => true,
        ]);

        return response()->json(['data' => [
            'id' => $barrier->id,
            'description' => $barrier->description,
            'coping_strategy' => $barrier->coping_strategy,
        ]], 201);
    }
}
