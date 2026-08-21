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
