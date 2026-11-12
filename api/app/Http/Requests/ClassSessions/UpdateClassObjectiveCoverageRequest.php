<?php

namespace App\Http\Requests\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\ClassSession;
use App\Models\UnitObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Marking an objective «todo» on a class that already recorded it when it
 * was closed (todo / una parte / nada). Screen 12's «Lo terminé» uses it to
 * turn «una parte» into «todo» on the class where it was recorded: the objective's
 * state keeps deriving only from the class record (P12-COB-02, screen 13
 * F-7), there is no separate checkbox.
 *
 * Only the plan's owning teacher who also leads the class's group may do it
 * (UnitPolicy::update + ClassSessionPolicy::update). Both bindings are
 * tenant-scoped, and an objective the class does not work is a 404.
 */
class UpdateClassObjectiveCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $class = $this->classSession();
        $objective = $this->objective();

        abort_unless($class->unitObjectives()->whereKey($objective->getKey())->exists(), 404);
        abort_unless($this->classBelongsToPlan($class, $objective), 404);

        return $this->user()->can('update', $class)
            && $this->user()->can('update', $objective->unit);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // «Lo terminé» only ever records «todo» (ClickUp 1dfd3mc4bg8, PO
            // 12/11); any other correction belongs to closing the class.
            'coverage' => ['required', 'string', Rule::in([ObjectiveCoverage::Full->value])],
        ];
    }

    /**
     * Only an already closed record can be corrected here: closing a class
     * for the first time belongs to the class screen, and a cancelled class
     * never counts.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                if ($this->classSession()->status === ClassSessionStatus::Cancelled) {
                    $validator->errors()->add('coverage', 'A cancelled class cannot record coverage.');

                    return;
                }
                if ($this->currentCoverage() === null) {
                    $validator->errors()->add('coverage', 'This class has not been closed for this objective yet.');
                }
            },
        ];
    }

    public function classSession(): ClassSession
    {
        /** @var ClassSession $class */
        $class = $this->route('classSession');

        return $class;
    }

    public function objective(): UnitObjective
    {
        /** @var UnitObjective $objective */
        $objective = $this->route('objective');

        return $objective;
    }

    /**
     * The class must be eligible for the objective's plan: same group, and,
     * when it hangs from a unit, a unit of a plan of that same group and
     * subject (ClickUp 1dfd3mc4bg8, PO 12/11). Both are tenant-scoped
     * already; this keeps a link built wrongly from correcting another
     * group's or subject's record.
     */
    protected function classBelongsToPlan(ClassSession $class, UnitObjective $objective): bool
    {
        $plan = $objective->unit?->annualPlan;

        if ($plan === null || $class->group_id !== $plan->group_id) {
            return false;
        }

        if ($class->unit_id === null) {
            return true;
        }

        $classPlan = $class->unit?->annualPlan;

        return $classPlan !== null
            && $classPlan->group_id === $plan->group_id
            && $classPlan->subject_id === $plan->subject_id;
    }

    protected function currentCoverage(): ?string
    {
        /** @var UnitObjective|null $linked */
        $linked = $this->classSession()->unitObjectives()->whereKey($this->objective()->getKey())->first();

        return $linked?->pivot?->coverage;
    }
}
