<?php

namespace App\Http\Requests;

use App\Enums\AlertRecipient;
use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Support\AlertRouting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change who one alert type reaches first (ClickUp 86e3jpzcv). At least one
 * recipient: an alert nobody receives would be generated for nothing. Only
 * types that are still created can be routed ({@see AlertRouting::configurableTypes()}):
 * the escalated alert is not any more (review of 14/10).
 */
class UpdateAlertRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('configureRouting', AlertRule::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => [Rule::in(array_map(fn (AlertType $type) => $type->value, AlertRouting::configurableTypes()))],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'distinct', Rule::in(AlertRecipient::values())],
        ];
    }

    /**
     * Validate the routed type too: it comes from the URL.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->route('type');

        $this->merge(['type' => $type instanceof AlertType ? $type->value : $type]);
    }
}
