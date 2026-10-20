<?php

namespace App\Http\Requests;

use App\Enums\AlertType;
use App\Models\AlertRule;
use App\Support\AlertRouting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change who one alert type reaches first (ClickUp 86e3jpzcv). At least one
 * recipient: an alert nobody receives would be generated for nothing. The
 * exception is the escalated alert (ClickUp 86e3jpzdp), which always reaches
 * the two people involved, so its role list may be empty. Only the
 * recipients allowed for the type are accepted: teachers never receive the
 * «Señal de dificultad» alert (ClickUp 86e3qkwpn).
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
        /** @var AlertType $type */
        $type = $this->route('type');

        return [
            'recipients' => $type === AlertType::EscalatedOverdue
                ? ['present', 'array']
                : ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'distinct', Rule::in(AlertRouting::allowedRecipients($type))],
        ];
    }
}
