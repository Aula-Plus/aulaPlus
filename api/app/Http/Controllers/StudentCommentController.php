<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStudentCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Comments on a Student (docs/prompts/04-seguimiento-institucional.md §1).
 * Authorization is fully handled by StoreStudentCommentRequest (create) /
 * StudentPolicy::view (list) — see those for why this reuses the Sesión 2
 * student-view rule instead of a parallel one.
 */
class StudentCommentController extends Controller
{
    public function index(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        $comments = $student->comments()
            ->visibleToRole(request()->user())
            ->latest()
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(StoreStudentCommentRequest $request, Student $student): JsonResponse
    {
        $comment = Comment::create([
            'author_id' => $request->user()->id,
            'commentable_type' => Student::class,
            'commentable_id' => $student->id,
            'content' => $request->validated('content'),
            'tone' => $request->validated('tone'),
            'visible_to' => $request->validated('visible_to'),
        ]);

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }
}
