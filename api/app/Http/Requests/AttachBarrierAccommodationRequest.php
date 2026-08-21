<?php

namespace App\Http\Requests;

use App\Models\Barrier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links an existing Accommodation to a Barrier (proposes it — validation is
 * a separate step, see BarrierAccommodationController::validateLink()).
 * Not specified as its own ability in docs/prompts/03-flujos-aprobacion-
 * trazabilidad.md §4, so this reuses the same authorization as editing the
 * barrier (BarrierPolicy::update — teacher/psychopedagogue), consistent with
 * "proposing a link" being a form of editing the barrier's accommodations.
 */
class AttachBarrierAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Barrier $barrier */
        $barrier = $this->route('barrier');

        return $this->user()->can('update', $barrier);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'accommodation_id' => [
                'required',
                'integer',
                Rule::exists('accommodations', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
        ];
    }
}
