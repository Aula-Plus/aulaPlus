<?php

namespace App\Http\Requests;

use App\Models\MeetingSheetType;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or rename one of the school's sheet types (P10-DEC-01, P23-DEC-61):
 * direction only. Names are unique within the school so two types never
 * read the same.
 */
class MeetingSheetTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MeetingSheetType|null $type */
        $type = $this->route('meetingSheetType');

        return $type === null
            ? $this->user()->can('create', MeetingSheetType::class)
            : $this->user()->can('update', $type);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var MeetingSheetType|null $type */
        $type = $this->route('meetingSheetType');

        return [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('meeting_sheet_types', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->ignore($type?->id),
            ],
        ];
    }
}
