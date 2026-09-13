<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes GET /groups/{group}/scheduled-follow-ups
 * (docs/prompts/21-perfil-de-grupo.md §4). Endpoint-level access is the same
 * as viewing the group (GroupPolicy::view): a teacher who does not lead the
 * group gets a 403. Per-student visibility of each follow-up is enforced
 * afterwards in the controller via ScheduledFollowUpPolicy::view. `overdue`
 * is an optional boolean filter.
 */
class GroupScheduledFollowUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $group = $this->route('group');

        return $group instanceof Group && $this->user()->can('view', $group);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A query-string flag: the `boolean` rule rejects the literal "true"/
        // "false" a URL carries, so accept the boolean-ish tokens explicitly.
        // `$request->boolean('overdue')` then normalizes them in the controller.
        return [
            'overdue' => ['nullable', 'in:1,0,true,false'],
        ];
    }
}
