<?php

namespace App\Http\Requests;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Buscar en Aula+» (screen 01, P01-DEC-118 / PA-134, ClickUp 1dfd3mc56kr).
 *
 * Every staff role may search: what each one finds is narrowed per record by
 * the same visibility scopes as the listings (see GlobalSearch), so this gate
 * reuses the listing policies instead of allowing everything blindly.
 *
 * The query may hold a student's name: it is validated here but never logged.
 */
class GlobalSearchRequest extends FormRequest
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 80;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('viewAny', Group::class) && $user->can('viewAny', Student::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH],
        ];
    }

    public function term(): string
    {
        return (string) $this->validated('q');
    }
}
