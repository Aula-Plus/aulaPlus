<?php

namespace App\Http\Requests;

use App\Models\Accommodation;
use App\Models\Barrier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links an existing Accommodation to a Barrier (proposes it — validation is
 * a separate step, see BarrierAccommodationController::validateLink()).
 * Not specified as its own ability in docs/prompts/03-flujos-aprobacion-
 * trazabilidad.md §4, so this reuses the same authorization as editing the
 * barrier (BarrierPolicy::update — psychopedagogue, or a teacher of the
 * student), consistent with "proposing a link" being a form of editing the
 * barrier's accommodations.
 *
 * On top of that the user must be allowed to see the accommodation itself
 * (AccommodationPolicy::view → Accommodation::scopeVisibleTo): a teacher can't
 * link — and so probe — one scoped to a subject they don't teach. When the id
 * can't be resolved we defer to the `exists` rule (422, not a misleading 403).
 * The accommodation must also belong to the barrier's own student.
 */
class AttachBarrierAccommodationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('update', $this->barrier())) {
            return false;
        }

        $accommodation = Accommodation::find($this->input('accommodation_id'));

        return $accommodation === null || $this->user()->can('view', $accommodation);
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
                Rule::exists('accommodations', 'id')
                    ->where('school_id', $this->user()->school_id)
                    ->where('student_id', $this->barrier()->student_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    protected function barrier(): Barrier
    {
        /** @var Barrier */
        return $this->route('barrier');
    }
}
