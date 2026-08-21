<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'commentable_type' => class_basename($this->commentable_type),
            'commentable_id' => $this->commentable_id,
            'content' => $this->content,
            'tone' => $this->tone?->value,
            'visible_to' => $this->visible_to,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
