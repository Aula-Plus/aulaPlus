<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentShareRequest;
use App\Http\Resources\CommentShareResource;
use App\Models\Comment;
use App\Models\CommentShare;
use App\Models\Student;
use App\Notifications\CommentSharedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * «Compartir con» extracts (ClickUp 86e3qkwnv) — see CommentShare for the
 * model and CommentSharePolicy for who may share and read.
 */
class CommentShareController extends Controller
{
    /**
     * GET /api/v1/students/{student}/comment-shares — the extracts on this
     * student the caller wrote or received. This is CommentSharePolicy::view
     * applied once for the whole list: every row belongs to `$student`, whose
     * access is authorized here, and the query keeps only rows the caller
     * authored or received — so no per-row policy call (and no per-row
     * "does this teacher teach the student" query) is needed.
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
            ->with('recipients.roles')
            ->latest()
            ->get();

        return CommentShareResource::collection($shares);
    }

    /**
     * POST /api/v1/comments/{comment}/shares — authorization and recipient
     * checks live in StoreCommentShareRequest. Each recipient gets a notice.
     */
    public function store(StoreCommentShareRequest $request, Comment $comment): JsonResponse
    {
        // One unit: an extract without its recipients (or recipients without
        // their notice) would be invisible to everyone but its author.
        $share = DB::transaction(function () use ($request, $comment): CommentShare {
            $share = CommentShare::create([
                'comment_id' => $comment->id,
                'student_id' => $comment->commentable_id,
                'author_id' => $request->user()->id,
                'content' => $request->validated('content'),
            ]);

            $share->recipients()->sync($request->validated('recipient_ids'));
            $share->load('recipients.roles');

            // Recipients only see the extract, so they are told it is there.
            foreach ($share->recipients as $recipient) {
                $recipient->notify(new CommentSharedNotification($share));
            }

            return $share;
        });

        return (new CommentShareResource($share))->response()->setStatusCode(201);
    }
}
