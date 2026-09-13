<?php

namespace App\Http\Requests;

use App\Enums\CommentTone;
use App\Enums\Role;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Authorization for commenting on a Group reuses GroupPolicy::view — same
 * pattern as StoreStudentCommentRequest (no parallel authorization rule).
 */
class StoreGroupCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Group $group */
        $group = $this->route('group');

        return $this->user()->can('view', $group);
    }

    /**
     * `author_only` (docs/prompts/19-comentarios-alcance.md §1) is the fourth,
     * strictest scope: the comment becomes visible only to its author. It is
     * mutually exclusive with `visible_to` (a role list can never narrow to a
     * single person) — {@see self::prepareForValidation()} forces `visible_to`
     * to null whenever `author_only` is true, so the two never coexist on a
     * stored comment.
     */
    protected function prepareForValidation(): void
    {
        if ($this->boolean('author_only')) {
            $this->merge(['visible_to' => null]);

            return;
        }

        // Normalize an explicit empty array to null so it means the same thing
        // ("visible to every role") in both visibility paths: Comment::isVisibleTo()
        // treats empty as visible-to-all, but Comment::scopeVisibleToRole() only
        // matches on `visible_to IS NULL` — a stored `[]` would diverge between
        // the two and break the invariant their docblocks promise.
        if ($this->input('visible_to') === []) {
            $this->merge(['visible_to' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'tone' => ['nullable', new Enum(CommentTone::class)],
            'author_only' => ['sometimes', 'boolean'],
            'visible_to' => ['nullable', 'array'],
            'visible_to.*' => [Rule::in(Role::values())],
        ];
    }
}
