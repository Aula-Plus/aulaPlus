<?php

namespace App\Http\Requests\SchoolYears;

use App\Models\Group;
use App\Models\SchoolYear;
use App\Services\SchoolYears\SchoolYearCalendar;
use App\Support\SchoolTime;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Direction enables loading in one group of a closed year (ClickUp
 * 1dfd3mc4bh7, decision 2), with a reason and the day until which it holds
 * (today at the earliest, at most a year ahead).
 */
class StoreGroupWriteWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWriteWindows', $this->schoolYear());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $today = SchoolTime::today();

        return [
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->where('school_id', Tenancy::schoolId())],
            'reason' => ['required', 'string', 'max:200'],
            'until_on' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$today->toDateString(),
                'before_or_equal:'.$today->addYear()->toDateString(),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('group_id')) {
                    return;
                }
                $group = Group::query()->find($this->integer('group_id'));
                if ($group === null || ! app(SchoolYearCalendar::class)->isInClosedYear($group)) {
                    $validator->errors()->add('group_id', 'Loading can only be enabled in a group of a closed year.');
                }
            },
        ];
    }

    public function schoolYear(): SchoolYear
    {
        return $this->route('schoolYear');
    }
}
