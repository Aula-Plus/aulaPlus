<?php

namespace App\Http\Requests\Materials;

use App\Enums\MaterialType;
use App\Models\Group;
use App\Models\Material;
use App\Models\Subject;
use App\Services\Materials\MaterialCurriculum;
use App\Services\Materials\MaterialScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Subir un recurso propio» (screen 19, P19-GEN-02 / PA-109): one file, the
 * group and subject it belongs to (only a pair the caller teaches, or any
 * pair of the school for Psicopedagogía), title, type, tags and the program
 * elements it works — chosen from the subject's catalog, never free text.
 *
 * `publish` also proposes it to the catalog, which needs
 * `confirm_no_student_data` («Revisé que no tenga datos de alumnos»).
 */
class StoreMaterialRequest extends FormRequest
{
    public const MAX_KILOBYTES = 20 * 1024;

    public const EXTENSIONS = ['pdf', 'docx', 'pptx', 'xlsx', 'odt', 'odp', 'ods', 'png', 'jpg', 'jpeg'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Material::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:'.implode(',', self::EXTENSIONS), 'max:'.self::MAX_KILOBYTES],
            'group_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(MaterialType::class)->except([MaterialType::Assessment])],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40', 'distinct'],
            'curricular_item_ids' => ['sometimes', 'array', 'max:50'],
            'curricular_item_ids.*' => ['integer', 'distinct'],
            'publish' => ['sometimes', 'boolean'],
            'confirm_no_student_data' => ['exclude_unless:publish,true,1', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_no_student_data.accepted' => 'Para proponerlo al catálogo, confirmá que revisaste que no tenga datos de alumnos.',
            'file.mimes' => 'El archivo tiene que ser PDF, Word, PowerPoint, Excel, OpenDocument o una imagen.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $groupId = (int) $this->input('group_id');
            $subjectId = (int) $this->input('subject_id');

            if (! app(MaterialScope::class)->canUploadTo($this->user(), $groupId, $subjectId)) {
                $validator->errors()->add('subject_id', 'Elegí un grupo y una materia en los que trabajes.');

                return;
            }

            $ids = collect($this->input('curricular_item_ids', []))->map(fn ($id) => (int) $id);
            if ($ids->isEmpty()) {
                return;
            }

            $allowed = app(MaterialCurriculum::class)
                ->itemsFor(Group::query()->findOrFail($groupId), Subject::query()->findOrFail($subjectId))
                ->pluck('id');

            if ($ids->diff($allowed)->isNotEmpty()) {
                $validator->errors()->add('curricular_item_ids', 'Elegí elementos del programa de esta materia.');
            }
        }];
    }
}
