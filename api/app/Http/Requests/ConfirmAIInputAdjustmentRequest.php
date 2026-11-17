<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAIInputComment;
use App\Models\AIInputMark;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /ai-input-marks/{mark}/adjustment-ok — «El ajuste está bien»
 * (psychopedagogy only), with an optional comment for the team.
 */
class ConfirmAIInputAdjustmentRequest extends FormRequest
{
    use ValidatesAIInputComment;

    public function authorize(): bool
    {
        return $this->user()->can('confirmAdjustment', $this->mark());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->commentRules();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $group = $this->mark()->group;

            if ($group !== null) {
                $this->rejectStudentNamesInComment($validator, $group);
            }
        }];
    }

    public function mark(): AIInputMark
    {
        /** @var AIInputMark */
        return $this->route('mark');
    }
}
