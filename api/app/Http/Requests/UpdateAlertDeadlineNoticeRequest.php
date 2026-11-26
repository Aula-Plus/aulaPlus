<?php

namespace App\Http\Requests;

use App\Models\Alert;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Switch «Avisarme a mí también cuando llegue el plazo» on or off (ClickUp
 * 86e3jpzdp). Only whoever handed the alert off may do it — see
 * {@see Alert::canToggleDeadlineNoticeBy()}.
 */
class UpdateAlertDeadlineNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Alert $alert */
        $alert = $this->route('alert');

        return $this->user()->can('toggleDeadlineNotice', $alert);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
