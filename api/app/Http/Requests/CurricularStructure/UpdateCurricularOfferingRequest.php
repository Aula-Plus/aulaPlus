<?php

namespace App\Http\Requests\CurricularStructure;

use App\Models\CurricularFramework;
use App\Models\CurricularOffering;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/groups/{group}/subjects/{subject}/curricular-offering — the
 * programs a group-subject follows. At least one, no repeats, and always an
 * ANEP one: the articulation with ANEP is mandatory (screen 23).
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
            'curricular_framework_ids' => ['required', 'array', 'min:1', 'max:5', $this->includesAnep()],
            'curricular_framework_ids.*' => ['integer', 'distinct', 'exists:curricular_frameworks,id'],
        ];
    }

    protected function includesAnep(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ids = array_filter((array) $value, 'is_int');

            if ($ids === [] || ! CurricularFramework::query()->whereKey($ids)->get()->contains->isAnep()) {
                $fail('The curricular offering must always include an ANEP program.');
            }
        };
    }
}
