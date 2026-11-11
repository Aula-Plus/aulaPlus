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
 * Correcting what was recorded for an objective when a class was closed
 * (todo / una parte / nada). Screen 12's «Lo terminé» uses it to turn «una
 * parte» into «todo» on the class where it was recorded: the objective's
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

        return $this->user()->can('update', $class)
            && $this->user()->can('update', $objective->unit);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'coverage' => ['required', 'string', Rule::enum(ObjectiveCoverage::class)],
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

    protected function currentCoverage(): ?string
    {
        /** @var UnitObjective|null $linked */
        $linked = $this->classSession()->unitObjectives()->whereKey($this->objective()->getKey())->first();

        return $linked?->pivot?->coverage;
    }
}
