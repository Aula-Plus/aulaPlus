<?php

namespace App\Http\Requests\CurricularStructure;

use App\Models\CurricularOffering;
use App\Models\PlannerFieldDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/v1/planner-settings/fields/{definition} — the school's name for a
 * catalog field and whether it is shown. A field an external program asks for
 * can be renamed but never hidden (screen 23).
 */
class UpdatePlannerFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', CurricularOffering::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'school_name' => ['present', 'nullable', 'string', 'max:120'],
            'shown' => ['required', 'boolean', $this->notHidingProgramField()],
        ];
    }

    protected function notHidingProgramField(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var PlannerFieldDefinition $definition */
            $definition = $this->route('definition');

            if ($definition->required_by_program && ! filter_var($value, FILTER_VALIDATE_BOOL)) {
                $fail('A field the program asks for can be renamed but not hidden.');
            }
        };
    }

    /** A blank name means "use the official one". */
    public function schoolName(): ?string
    {
        $name = trim((string) $this->validated('school_name'));

        return $name === '' ? null : $name;
    }
}
