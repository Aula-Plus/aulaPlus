<?php

namespace App\Http\Requests\CurricularStructure;

use App\Models\CurricularOffering;
use App\Services\CurricularStructure\CurricularOfferings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/groups/{group}/subjects/{subject}/curricular-offering — the
 * programs a group-subject follows. No repeats, only programs the school has
 * active for the group's level (CurricularOfferings::eligibleFor), and the
 * mandatory ones of that level are always kept (the controller adds them;
 * P23-CAT-01). A group without a level must still include one of the
 * school's mandatory programs.
 */
class UpdateCurricularOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', [CurricularOffering::class, $this->route('group'), $this->route('subject')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'curricular_framework_ids' => ['present', 'array', 'max:5', $this->includesMandatory()],
            'curricular_framework_ids.*' => ['integer', 'distinct', Rule::in($this->eligibleIds())],
        ];
    }

    /** @return list<int> */
    protected function eligibleIds(): array
    {
        return app(CurricularOfferings::class)->eligibleFor($this->route('group'))->pluck('id')->all();
    }

    protected function includesMandatory(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $offerings = app(CurricularOfferings::class);
            $group = $this->route('group');
            $ids = array_filter((array) $value, 'is_int');

            if ($offerings->mandatoryFor($group)->isNotEmpty()) {
                return; // kept automatically
            }

            $schoolMandatory = $offerings->mandatoryForSchool($group->school_id)->pluck('id');
            if ($schoolMandatory->isNotEmpty() && $schoolMandatory->intersect($ids)->isEmpty()) {
                $fail('The curricular offering must include one of the school\'s mandatory programs.');
            }

            if ($ids === [] && $schoolMandatory->isEmpty()) {
                $fail('The curricular offering needs at least one program.');
            }
        };
    }
}
