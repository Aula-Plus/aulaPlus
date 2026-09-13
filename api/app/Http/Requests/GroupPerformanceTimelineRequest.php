<?php

namespace App\Http\Requests;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the ?from=&to= window of the group performance timeline
 * (docs/prompts/21-perfil-de-grupo.md §2) and authorizes it with the same
 * rule as GET /groups/{group}/tracking — GroupPolicy::view. Both dates are
 * optional; when both are present, `to` may not precede `from`.
 */
class GroupPerformanceTimelineRequest extends FormRequest
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
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
