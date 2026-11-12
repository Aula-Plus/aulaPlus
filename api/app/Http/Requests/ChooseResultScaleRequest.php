<?php

namespace App\Http\Requests;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Services\ResultScales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Choosing which official scale an assessment records its results with, when
 * its group has more than one valid Primaria scale (ClickUp 1dfd3mc4bgg, PO
 * answer of 12/11). Only the owning teacher (AssessmentResultPolicy::create),
 * only among the group's valid scales, and only while no result has been
 * recorded with a level yet, so recorded results never change scale.
 */
class ChooseResultScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [AssessmentResult::class, $this->assessment()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $group = $this->assessment()->group;
        $valid = $group
            ? app(ResultScales::class)->primaryScalesForGroup($group)->pluck('id')->all()
            : [];

        return [
            'official_scale_id' => ['required', 'integer', Rule::in($valid)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $chosen = $this->assessment()->official_scale_id;

                if ($chosen !== null
                    && $chosen !== (int) $this->input('official_scale_id')
                    && $this->assessment()->results()->whereNotNull('official_scale_level_id')->exists()) {
                    $validator->errors()->add(
                        'official_scale_id',
                        'Results were already recorded with another scale.',
                    );
                }
            },
        ];
    }

    protected function assessment(): Assessment
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment;
    }
}
