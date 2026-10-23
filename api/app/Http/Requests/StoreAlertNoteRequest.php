<?php

namespace App\Http\Requests;

use App\Models\Alert;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Leave a note in an alert's thread (ClickUp 86e3jpzdp follow-up), by whoever
 * has the alert. Authorization is AlertPolicy::note. The note is operational
 * — what was done with the case — never a diagnosis (documento vivo screen
 * 11, "línea roja"); it is bounded like the hand-off note.
 */
class StoreAlertNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Alert $alert */
        $alert = $this->route('alert');

        return $this->user()->can('note', $alert);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
