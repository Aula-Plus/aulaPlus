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
 * The query may hold a student's name: it is validated here but never logged,
 * and it is read from the request body only (POST), never from the URL, so it
 * stays out of access logs and browser history (Eitán, 26/11).
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
     * The body only: a `q` in the query string is ignored.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->getInputSource()->all();
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
