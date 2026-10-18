<?php

namespace App\Http\Resources;

use App\Models\CommentShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extract shared by psychopedagogy (ClickUp 86e3qkwnv). A recipient gets
 * the extract text only: the link to the original observation (`comment_id`)
 * and the recipient list are returned to the extract's author alone, so a
 * recipient learns nothing about the original or about who else received it.
 *
 * @mixin CommentShare
 */
class CommentShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAuthor = $request->user()?->id === $this->author_id;

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'author_id' => $this->author_id,
            'content' => $this->content,
            'comment_id' => $this->when($isAuthor, fn () => $this->comment_id),
            'recipients' => $this->when(
                $isAuthor && $this->relationLoaded('recipients'),
                fn () => StaffMemberResource::collection($this->recipients->loadMissing('roles')),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
