<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStudentCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\CommentCategory;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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
            ->with(['categories', 'author.roles'])
            ->visibleToRole(request()->user())
            ->latest()
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(StoreStudentCommentRequest $request, Student $student): JsonResponse
    {
        $comment = new Comment([
            'author_id' => $request->user()->id,
            'commentable_type' => Student::class,
            'commentable_id' => $student->id,
            'content' => $request->validated('content'),
            'visible_to' => $request->validated('visible_to'),
            'visible_to_users' => $request->visibleToUsers(),
            'author_only' => $request->boolean('author_only'),
        ]);

        // Loaded before saving so the change history's "alta" entry lists the
        // categories too (StudentFileHistory) — the pivot rows only exist
        // after the `created` event. One transaction: an observation never
        // stays saved without the categories (and their signal) its entry
        // says it has.
        $categoriesWithSignals = $request->categoriesWithSignals();
        $categories = CommentCategory::whereKey(array_keys($categoriesWithSignals))->get();
        $comment->setRelation('categories', $categories);

        DB::transaction(function () use ($comment, $categoriesWithSignals): void {
            $comment->save();
            $comment->categories()->sync($categoriesWithSignals);
        });

        $comment->load('categories');

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }
}
