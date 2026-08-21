<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGroupCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Comments on a Group — same pattern as StudentCommentController, reusing
 * GroupPolicy::view for authorization (docs/prompts/04-seguimiento-
 * institucional.md §1).
 */
class GroupCommentController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $this->authorize('view', $group);

        $comments = $group->comments()
            ->visibleToRole(request()->user())
            ->latest()
            ->get();

        return CommentResource::collection($comments);
    }

    public function store(StoreGroupCommentRequest $request, Group $group): JsonResponse
    {
        $comment = Comment::create([
            'author_id' => $request->user()->id,
            'commentable_type' => Group::class,
            'commentable_id' => $group->id,
            'content' => $request->validated('content'),
            'tone' => $request->validated('tone'),
            'visible_to' => $request->validated('visible_to'),
        ]);

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }
}
