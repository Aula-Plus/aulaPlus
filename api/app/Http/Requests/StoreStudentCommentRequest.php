<?php

namespace App\Http\Requests;

use App\Enums\CommentTone;
use App\Enums\Role;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Authorization for commenting on a Student reuses StudentPolicy::view — same
 * rules as viewing the student's profile (teacher only if they teach the
 * student; psychopedagogue/director unrestricted). Per docs/prompts/
 * 04-seguimiento-institucional.md §1, this is intentional: no parallel
 * authorization rule for comments.
 */
class StoreStudentCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Student $student */
        $student = $this->route('student');

        return $this->user()->can('view', $student);
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
