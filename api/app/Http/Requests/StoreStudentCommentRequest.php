<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Http\Requests\Concerns\ValidatesCommentCategorySignals;
use App\Models\Comment;
use App\Models\Student;
use App\Models\User;
use App\Policies\ScheduledFollowUpPolicy;
use App\Support\Tenancy;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Authorization for commenting on a Student reuses StudentPolicy::view — same
 * rules as viewing the student's profile (teacher only if they teach the
 * student; psychopedagogue/director unrestricted). Per docs/prompts/
 * 04-seguimiento-institucional.md §1, this is intentional: no parallel
 * authorization rule for comments.
 */
class StoreStudentCommentRequest extends FormRequest
{
    use ValidatesCommentCategorySignals;

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
        // «Elegir personas» (ClickUp 86e3qkwnv): an empty pick for a role
        // means "the whole role", so empty lists are dropped and an empty map
        // is stored as null. Never alongside `author_only`.
        $people = $this->input('visible_to_users');
        if (is_array($people) && ! array_is_list($people)) {
            $people = array_filter($people, fn ($ids) => $ids !== [] && $ids !== null);
            $this->merge(['visible_to_users' => $people === [] ? null : $people]);
        } elseif ($people === []) {
            $this->merge(['visible_to_users' => null]);
        }

        if ($this->boolean('author_only')) {
            $this->merge(['visible_to' => null, 'visible_to_users' => null]);

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
            // One signal per category (ClickUp 86e3qkwpn) — see the trait.
            ...$this->categorySignalRules(),
            'author_only' => ['sometimes', 'boolean'],
            'visible_to' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                // Only the four selector presets are storable; free role
                // combinations are not (null and author_only are the others).
                if (is_array($value) && ! in_array($value, Comment::visibleToPresets(), true)) {
                    $fail('The selected visibility is not one of the allowed scopes.');
                }
            }],
            'visible_to.*' => [Rule::in(Role::values())],
            // { role: [user ids] } — checked against the chosen option and
            // the student in after().
            'visible_to_users' => ['nullable', 'array'],
            'visible_to_users.*' => ['array', 'min:1', 'max:20'],
            'visible_to_users.*.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Every role picked in «Elegir personas» must be one the chosen option
     * reaches, and every person must hold that role, be active and already
     * see the student (same rule as follow-up responsibles) — never trusted
     * from the client.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateCategorySignals($validator), function (Validator $validator): void {
            $people = $this->input('visible_to_users');
            if ($validator->errors()->isNotEmpty() || ! is_array($people)) {
                return;
            }

            /** @var Student $student */
            $student = $this->route('student');
            $optionRoles = $this->input('visible_to') ?: Role::values();
            $followUps = app(ScheduledFollowUpPolicy::class);

            foreach ($people as $role => $ids) {
                if (! in_array($role, $optionRoles, true)) {
                    $validator->errors()->add('visible_to_users', 'People can only be picked within the roles of the chosen scope.');

                    return;
                }

                foreach ($ids as $id) {
                    $person = User::query()->find((int) $id);

                    if ($person === null
                        || $person->disabled_at !== null
                        || ! $person->hasRole($role)
                        || ! $followUps->canFollow($person, $student)) {
                        $validator->errors()->add('visible_to_users', 'One of the selected people cannot see this student.');

                        return;
                    }
                }
            }
        }];
    }

    /**
     * The validated «Elegir personas» map with integer ids, or null.
     *
     * @return array<string, list<int>>|null
     */
    public function visibleToUsers(): ?array
    {
        $people = $this->validated('visible_to_users');

        return $people === null ? null : array_map(
            fn (array $ids) => array_values(array_map('intval', $ids)),
            $people,
        );
    }
}
