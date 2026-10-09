<?php

namespace App\Http\Requests;

use App\Enums\AlertRecipient;
use App\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change who one alert type reaches first (ClickUp 86e3jpzcv). At least one
 * recipient: an alert nobody receives would be generated for nothing.
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
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'distinct', Rule::in(AlertRecipient::values())],
        ];
    }
}
