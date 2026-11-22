<?php

namespace App\Http\Requests\Resources;

use App\Enums\ResourceVisibility;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A resource added to a group (PA-085, ClickUp 1dfd3mc4bh4): its name and who
 * sees it. Only a teacher chooses `own` («solo mío»); Dirección and
 * Psicopedagogía have no own resources, so `own` from them is a 422.
 * Authorization is in the controller (GroupPolicy::manageResources).
 */
class StoreGroupResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $allowed = $this->user()->hasAnyRole(Role::schoolWideValues())
            ? [ResourceVisibility::Group->value]
            : ResourceVisibility::values();

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'visibility' => ['sometimes', 'string', Rule::in($allowed)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visibility.in' => 'Dirección y Psicopedagogía suman recursos para todo el grupo.',
        ];
    }

    public function visibility(): ResourceVisibility
    {
        return ResourceVisibility::from($this->validated('visibility', ResourceVisibility::Group->value));
    }
}
