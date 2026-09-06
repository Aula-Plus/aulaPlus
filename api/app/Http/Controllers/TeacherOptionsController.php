<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight, read-only directory of teachers in the caller's school, used
 * to populate the "docente a cargo" selector on GroupFormPage. Director-only
 * — the only place this is consumed today is the group create/edit form.
 */
class TeacherOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(Role::Director->value), 403);

        $teachers = User::query()
            ->where('school_id', $request->user()->school_id)
            ->role(Role::Teacher->value)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $teachers]);
    }
}
