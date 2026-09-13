<?php

namespace App\Http\Requests;

use App\Models\Accommodation;
use App\Models\AccommodationInstanceOverride;
use App\Models\Assessment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deactivating an accommodation for one assessment instance
 * (docs/prompts/18-ajustes-categoria-instancia.md §2). Body: { assessment_id,
 * reason }; the accommodation comes from the route.
 *
 * Two layers, matching the spec:
 * - Ownership (403): only the teacher who owns the assessment may create the
 *   override — enforced in authorize() via AccommodationInstanceOverridePolicy.
 * - Group membership (422): the accommodation must belong to a student enrolled
 *   in the assessment's group — validated below. `Student` has no `group_id`;
 *   membership lives in the `group_student` pivot, hence the `students()` query.
 *
 * When the assessment can't be resolved (missing / invalid / cross-tenant) we
 * defer to the `exists` rule so the client gets a 422, not a misleading 403.
 */
class StoreAccommodationInstanceOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = Assessment::find($this->input('assessment_id'));

        if ($assessment === null) {
            return true;
        }

        return $this->user()->can('create', [AccommodationInstanceOverride::class, $assessment]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assessment_id' => [
                'required',
                'integer',
                Rule::exists('assessments', 'id')->where(
                    fn ($query) => $query->where('school_id', $this->user()->school_id)
                ),
            ],
            'reason' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Accommodation $accommodation */
            $accommodation = $this->route('accommodation');
            $assessment = Assessment::find($this->input('assessment_id'));

            // The `exists` rule already reports an unresolvable assessment.
            if ($assessment === null) {
                return;
            }

            $belongsToGroup = $assessment->group
                ->students()
                ->whereKey($accommodation->student_id)
                ->exists();

            if (! $belongsToGroup) {
                $validator->errors()->add(
                    'accommodation',
                    "The accommodation must belong to a student in the assessment's group."
                );
            }
        });
    }
}
