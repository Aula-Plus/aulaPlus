<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentShareRequest;
use App\Http\Resources\CommentShareResource;
use App\Models\Comment;
use App\Models\CommentShare;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * «Compartir con» extracts (ClickUp 86e3qkwnv) — see CommentShare for the
 * model and CommentSharePolicy for who may share and read.
 */
class CommentShareController extends Controller
{
    /**
     * GET /api/v1/students/{student}/comment-shares — the extracts on this
     * student the caller wrote or received. Each row is re-checked against
     * CommentSharePolicy::view.
     */
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);
        $user = $request->user();

        $shares = CommentShare::query()
            ->where('student_id', $student->id)
            ->where(function ($query) use ($user): void {
                $query->where('author_id', $user->id)
                    ->orWhereHas('recipients', fn ($q) => $q->whereKey($user->id));
            })
            ->with(['recipients.roles', 'student'])
            ->latest()
            ->get()
            ->filter(fn (CommentShare $share) => $user->can('view', $share));

        return CommentShareResource::collection($shares->values());
    }

    /**
     * POST /api/v1/comments/{comment}/shares — authorization and recipient
     * checks live in StoreCommentShareRequest.
     */
    public function store(StoreCommentShareRequest $request, Comment $comment): JsonResponse
    {
        $share = CommentShare::create([
            'comment_id' => $comment->id,
            'student_id' => $comment->commentable_id,
            'author_id' => $request->user()->id,
            'content' => $request->validated('content'),
        ]);

        $share->recipients()->sync($request->validated('recipient_ids'));
        $share->load('recipients.roles');

        return (new CommentShareResource($share))->response()->setStatusCode(201);
    }
}
