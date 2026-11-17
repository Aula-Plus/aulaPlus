<?php

namespace App\Http\Requests;

use App\Models\ExternalMeasure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Who each row of an uploaded CSV is (screen 30 «Datos», MatchTemplateStudents):
 * the Aula+ code and/or the name and group as the file has them. Only who
 * may load the datum asks (ExternalMeasurePolicy::update: Psicopedagogía and
 * Dirección of the datum's school).
 */
class MatchExternalMeasureStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExternalMeasure $measure */
        $measure = $this->route('externalMeasure');

        return $this->user()->can('update', $measure);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['required', 'array:student_id,name,group'],
            'rows.*.student_id' => ['nullable', 'integer', 'min:1'],
            'rows.*.name' => ['nullable', 'string', 'max:200'],
            'rows.*.group' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (array_values((array) $this->input('rows')) as $index => $row) {
                if (($row['student_id'] ?? null) === null && trim((string) ($row['name'] ?? '')) === '') {
                    $validator->errors()->add("rows.{$index}", 'Each row needs the student code or the name.');
                }
            }
        }];
    }

    /**
     * @return list<array{student_id: int|null, name: string|null, group: string|null}>
     */
    public function rows(): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'student_id' => isset($row['student_id']) ? (int) $row['student_id'] : null,
            'name' => $row['name'] ?? null,
            'group' => $row['group'] ?? null,
        ], (array) $this->validated('rows')));
    }
}
