<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Comment;
use App\Models\Group;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

            return;
        }

        // The selector's presets (ClickUp 86e3qkwnv): canonicalize before the
        // preset check below, so an older client still sending "solo
        // dirección" (`["director"]`) stores "Dirección y Psicopedagogía".
        $visibleTo = $this->input('visible_to');
        if (is_array($visibleTo) && array_is_list($visibleTo) && collect($visibleTo)->every(fn ($role) => is_string($role))) {
            $this->merge(['visible_to' => Comment::normalizeVisibleTo($visibleTo)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            // Optional, several allowed, only this school's categories.
            'category_ids' => ['sometimes', 'array', 'max:10'],
            'category_ids.*' => [
                'integer', 'distinct',
                Rule::exists('comment_categories', 'id')->where('school_id', Tenancy::schoolId()),
            ],
            'author_only' => ['sometimes', 'boolean'],
            'visible_to' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                // Only the four selector presets are storable; free role
                // combinations are not (null and author_only are the others).
                if (is_array($value) && ! in_array($value, Comment::visibleToPresets(), true)) {
                    $fail('The selected visibility is not one of the allowed scopes.');
                }
            }],
            'visible_to.*' => [Rule::in(Role::values())],
        ];
    }
}
