<?php

namespace App\Http\Requests\Concerns;

use App\Models\Group;
use App\Support\StudentNameMatcher;
use Illuminate\Contracts\Validation\Validator;

/**
 * Psychopedagogy's optional comment on a «Lo que recibe la IA» mark (ClickUp
 * 1dfd3mc2wx2). It is e-mailed to the Aula+ team, out of the permission
 * circuit: the form warns «No escribas nombres de alumnos ni datos de salud»
 * and, as defence in depth, a comment holding the name of a student of the
 * group (or the full name of any student of the school) is rejected — the
 * same matcher that keeps names out of the AI input.
 */
trait ValidatesAIInputComment
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function commentRules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function rejectStudentNamesInComment(Validator $validator, Group $group): void
    {
        $comment = trim((string) $this->input('comment'));

        if ($comment === '' || $validator->errors()->has('comment')) {
            return;
        }

        if (StudentNameMatcher::forGroup($group)->containsAnyName($comment)) {
            $validator->errors()->add('comment', 'No escribas nombres de alumnos en el comentario.');
        }
    }

    /**
     * The comment as stored: trimmed, null when empty.
     */
    public function comment(): ?string
    {
        $comment = trim((string) $this->validated('comment'));

        return $comment === '' ? null : $comment;
    }
}
