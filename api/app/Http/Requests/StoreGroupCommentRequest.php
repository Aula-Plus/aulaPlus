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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'tone' => ['nullable', new Enum(CommentTone::class)],
            'visible_to' => ['nullable', 'array'],
            'visible_to.*' => [Rule::in(Role::values())],
        ];
    }
}
