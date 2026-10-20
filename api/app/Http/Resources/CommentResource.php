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
            // Only the student list loads the author; name + role are shown so the
            // team can tell who wrote each observation. No email or other PII.
            'author' => $this->whenLoaded('author', fn () => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'role' => $this->author->getRoleNames()->first(),
            ]),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values()->all()),
            'visible_to' => $this->visible_to,
            'author_only' => $this->author_only,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
