<?php

namespace App\Http\Requests\ClassSessions;

use App\Enums\ClassSessionStatus;
use App\Enums\ObjectiveCoverage;
use App\Models\ClassSession;
use App\Models\UnitObjective;
use App\Services\AnnualPlans\ClosableClasses;
use App\Support\SchoolTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Lo terminé» (screen 12): record an objective as «todo» on a class the
 * teacher picks — the recent classes of the plan's group and subject, or one
 * found by searching (ClickUp 1dfd3mc4bg8, PO 14/11). It works even if that
 * class did not work the objective yet (the link is created), and it never
 * touches any other class's closing record. The objective's state keeps
 * deriving only from the class records (P12-COB-02, screen 13 F-7): there is
 * no separate checkbox.
 *
 * Only the plan's owning teacher who also owns the class may do it
 * (UnitPolicy::update + ClassSessionPolicy::update). Both bindings are
 * tenant-scoped, and a class outside the plan's group and subject is a 404.
 */
class UpdateClassObjectiveCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $class = $this->classSession();
        $objective = $this->objective();

        abort_unless(
            app(ClosableClasses::class)->eligible($objective)->whereKey($class->getKey())->exists(),
            404,
        );

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
     * A cancelled class never counts, and a class that has not happened yet
     * cannot have finished anything.
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
                $class = $this->classSession();
                if ($class->status === ClassSessionStatus::Cancelled) {
                    $validator->errors()->add('coverage', 'A cancelled class cannot record coverage.');

                    return;
                }
                // Compared on the school's calendar day, not UTC's.
                if ($class->date !== null && $class->date->toDateString() > SchoolTime::today()->toDateString()) {
                    $validator->errors()->add('coverage', 'A class that has not happened yet cannot record coverage.');
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
}
