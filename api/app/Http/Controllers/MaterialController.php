<?php

namespace App\Http\Controllers;

use App\Enums\MaterialCatalogStatus;
use App\Enums\MaterialSource;
use App\Enums\Role;
use App\Http\Requests\Materials\ListMaterialsRequest;
use App\Http\Requests\Materials\PublishMaterialRequest;
use App\Http\Requests\Materials\RemoveFromCatalogRequest;
use App\Http\Requests\Materials\StoreMaterialRequest;
use App\Models\Assessment;
use App\Models\CurricularItem;
use App\Models\Group;
use App\Models\Material;
use App\Models\Subject;
use App\Models\User;
use App\Services\Materials\MaterialCatalog;
use App\Services\Materials\MaterialCurriculum;
use App\Services\Materials\MaterialScope;
use App\Support\MaterialStorage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Screen 19 «Materiales» (ClickUp 1dfd3mc4bgx): «Lo generado» (the caller's
 * confirmed assessments and uploaded files; Psicopedagogía and Dirección see
 * the whole school's) and «Institucional» (the school's catalog), always
 * behind the subject × grade prefilter (P19-DEC-02).
 *
 * Uploaded files sit on the private `materials` disk under a random name and
 * open only through a short-lived temporary URL (CLAUDE.md rule 7). Nothing
 * here reaches the AI: uploading has no AI step yet (PA-109).
 */
class MaterialController extends Controller
{
    protected const URL_TTL_MINUTES = 5;

    protected const PER_PAGE = 50;

    public function __construct(
        private readonly MaterialScope $scope,
        private readonly MaterialCatalog $catalog,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->scope->options($request->user())]);
    }

    public function index(ListMaterialsRequest $request): JsonResponse
    {
        $user = $request->user();
        $subjectId = (int) $request->validated('subject_id');
        $gradeId = $request->gradeId();
        $schoolWide = $user->hasAnyRole(Role::schoolWideValues());

        $materials = Material::query()
            ->with(['owner:id,name', 'publishedBy:id,name', 'group:id,name', 'curricularItems:id,code,name'])
            ->where('subject_id', $subjectId)
            ->when($gradeId === null, fn (Builder $q) => $q->whereNull('school_grade_id'), fn (Builder $q) => $q->where('school_grade_id', $gradeId));

        if ($request->validated('tab') === 'catalog') {
            $page = $materials
                ->where('catalog_status', MaterialCatalogStatus::Published->value)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE);

            return $this->pageResponse($page, $page->getCollection()
                ->map(fn (Material $material) => $this->materialRow($material, $user, inCatalog: true)));
        }

        // «Lo generado» mixes two tables. Both grow every year, so the merge
        // and the page are done in SQL over (kind, id, created_at), and only
        // the rows of the requested page are loaded with their relations.
        $uploads = $materials->clone()
            ->setEagerLoads([])
            ->where('source', MaterialSource::Upload->value)
            ->when(! $schoolWide, fn (Builder $q) => $q->where('owner_id', $user->id))
            ->select(['materials.id', DB::raw("'upload' as kind"), 'materials.created_at']);

        $assessments = Assessment::query()
            ->where('subject_id', $subjectId)
            ->whereHas('group', fn (Builder $q) => $gradeId === null ? $q->whereNull('school_grade_id') : $q->where('school_grade_id', $gradeId))
            ->when(! $schoolWide, fn (Builder $q) => $q->where('teacher_id', $user->id))
            ->select(['assessments.id', DB::raw("'assessment' as kind"), 'assessments.created_at']);

        $page = DB::query()
            ->fromSub($uploads->toBase()->unionAll($assessments->toBase()), 'generated_rows')
            ->orderByDesc('created_at')
            ->orderBy('kind')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $ids = collect($page->items())->groupBy('kind')->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        $uploadModels = Material::query()
            ->with(['owner:id,name', 'publishedBy:id,name', 'group:id,name', 'curricularItems:id,code,name'])
            ->whereKey($ids->get('upload', []))
            ->get()
            ->keyBy('id');
        $assessmentModels = Assessment::query()
            ->with(['group:id,name,school_grade_id', 'teacher:id,name', 'curricularItems:id,code,name'])
            ->whereKey($ids->get('assessment', []))
            ->get()
            ->keyBy('id');
        $copies = Material::query()
            ->whereIn('assessment_id', $assessmentModels->keys())
            ->get(['id', 'school_id', 'assessment_id', 'owner_id', 'catalog_status'])
            ->keyBy('assessment_id');

        $rows = collect($page->items())
            ->map(fn (object $row) => $row->kind === 'upload'
                ? $this->materialRow($uploadModels->get((int) $row->id), $user, inCatalog: false)
                : $this->assessmentRow($assessmentModels->get((int) $row->id), $copies->get((int) $row->id), $user));

        return $this->pageResponse($page, $rows);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    protected function pageResponse(LengthAwarePaginator $page, Collection $rows): JsonResponse
    {
        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function curricularItems(Request $request, MaterialCurriculum $curriculum): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
        ]);

        abort_unless($this->scope->canUploadTo($request->user(), (int) $validated['group_id'], (int) $validated['subject_id']), 403);

        $items = $curriculum->itemsFor(
            Group::query()->findOrFail($validated['group_id']),
            Subject::query()->findOrFail($validated['subject_id']),
        );

        return response()->json(['data' => $items->map(fn (CurricularItem $item) => MaterialCurriculum::row($item))->values()]);
    }

    public function store(StoreMaterialRequest $request): JsonResponse
    {
        if (! MaterialStorage::isDurable()) {
            Log::error('material.storage_not_durable', ['disk_driver' => 'local']);

            return response()->json([
                'message' => 'Por ahora no se pueden subir materiales: falta configurar el almacenamiento de archivos. Avisale al equipo de Aula+.',
            ], 503);
        }

        $user = $request->user();
        $file = $request->file('file');
        $format = strtolower((string) $file->guessExtension() ?: (string) $file->getClientOriginalExtension());
        $group = Group::query()->findOrFail($request->integer('group_id'));

        $path = $file->storeAs(
            "schools/{$user->school_id}/materials",
            Str::uuid().'.'.$format,
            ['disk' => MaterialStorage::DISK, 'visibility' => 'private'],
        );

        // The file is stored before the row exists; if the row cannot be
        // saved, nothing must stay on the disk that no row points to (it is a
        // file nobody has reviewed yet).
        try {
            $material = DB::transaction(function () use ($request, $user, $file, $format, $group, $path): Material {
                $material = Material::create([
                    'owner_id' => $user->id,
                    'group_id' => $group->id,
                    'subject_id' => $request->integer('subject_id'),
                    'school_grade_id' => $group->school_grade_id,
                    'source' => MaterialSource::Upload,
                    'title' => $request->validated('title'),
                    'type' => $request->validated('type'),
                    'tags' => array_values($request->validated('tags', [])),
                    'path' => $path,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'format' => $format,
                    'size' => $file->getSize(),
                ]);
                $material->curricularItems()->sync($request->validated('curricular_item_ids', []));

                if ($request->boolean('publish')) {
                    $this->catalog->publishUpload($material, $user);
                }

                return $material;
            });
        } catch (\Throwable $e) {
            MaterialStorage::disk()->delete($path);

            throw $e;
        }

        return response()->json(['data' => $this->materialRow($material->fresh(['owner:id,name', 'publishedBy:id,name', 'group:id,name', 'curricularItems:id,code,name']), $user, inCatalog: false)], 201);
    }

    /** A material with its content (the catalog copy of an assessment carries it). */
    public function show(Request $request, Material $material): JsonResponse
    {
        $this->authorize('view', $material);
        $material->load(['owner:id,name', 'publishedBy:id,name', 'group:id,name', 'curricularItems:id,code,name']);

        return response()->json(['data' => [
            ...$this->materialRow($material, $request->user(), inCatalog: $material->isPublished() && $material->owner_id !== $request->user()->id),
            'content' => $material->content,
        ]]);
    }

    public function download(Request $request, Material $material): JsonResponse
    {
        $this->authorize('view', $material);
        abort_unless($material->isUpload() && $material->path !== null, 404);

        $url = MaterialStorage::disk()->temporaryUrl($material->path, now()->addMinutes(self::URL_TTL_MINUTES));

        return response()->json(['data' => [
            'url' => $url,
            'name' => $material->original_name,
            'expires_in_minutes' => self::URL_TTL_MINUTES,
        ]]);
    }

    public function publish(PublishMaterialRequest $request, Material $material): JsonResponse
    {
        if (! $material->isUpload()) {
            return response()->json(['message' => 'Este material se publica desde su evaluación.'], 422);
        }

        $this->catalog->publishUpload($material, $request->user());

        return $this->rowResponse($material, $request->user());
    }

    public function publishAssessment(Request $request, Assessment $assessment): JsonResponse
    {
        // Only the teacher who confirmed it shares it (P19-GEN-01 «Proponer al catálogo»).
        $this->authorize('update', $assessment);

        $material = $this->catalog->publishAssessment($assessment, $request->user());

        return $this->rowResponse($material, $request->user());
    }

    public function removeFromCatalog(RemoveFromCatalogRequest $request, Material $material): JsonResponse
    {
        $this->catalog->removeFromCatalog($material, $request->user(), $request->reason());

        return $this->rowResponse($material, $request->user());
    }

    public function destroy(Material $material): JsonResponse
    {
        $this->authorize('delete', $material);

        $path = $material->path;
        $material->delete();
        if ($path !== null) {
            MaterialStorage::disk()->delete($path);
        }

        return response()->json(null, 204);
    }

    protected function rowResponse(Material $material, User $user): JsonResponse
    {
        $material->load(['owner:id,name', 'publishedBy:id,name', 'group:id,name', 'curricularItems:id,code,name']);

        return response()->json(['data' => $this->materialRow($material, $user, inCatalog: false)]);
    }

    /**
     * One row of either tab. In the catalog the group is never shown: what is
     * published is the resource, not the case (screen 19 red line).
     *
     * @return array<string, mixed>
     */
    protected function materialRow(Material $material, User $user, bool $inCatalog): array
    {
        $mine = $material->owner_id === $user->id;

        return [
            'kind' => $material->source->value,
            'id' => $material->id,
            'material_id' => $material->id,
            'assessment_id' => $material->assessment_id,
            'title' => $material->title,
            'type' => $material->type->value,
            'tags' => $material->tags ?? [],
            'format' => $material->format,
            'group' => $inCatalog || $material->group === null ? null : ['id' => $material->group->id, 'name' => $material->group->name],
            'owner_name' => $material->owner?->name,
            'is_mine' => $mine,
            'catalog_status' => $material->catalog_status?->value,
            'published_at' => $material->published_at?->toIso8601String(),
            'published_by_name' => $material->publishedBy?->name,
            'curricular_items' => $material->curricularItems->map(fn (CurricularItem $item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name])->values(),
            'created_at' => $material->created_at?->toIso8601String(),
            'can' => [
                'publish' => $mine && $material->isUpload() && ! $material->isPublished(),
                'remove_from_catalog' => $user->can('removeFromCatalog', $material),
                'delete' => $mine && $material->isUpload(),
            ],
        ];
    }

    /**
     * A confirmed assessment in «Lo generado» (PA-109: «llega solo»).
     *
     * @return array<string, mixed>
     */
    protected function assessmentRow(Assessment $assessment, ?Material $copy, User $user): array
    {
        $mine = $assessment->teacher_id === $user->id;
        $published = $copy?->catalog_status === MaterialCatalogStatus::Published;

        return [
            'kind' => 'assessment',
            'id' => $assessment->id,
            'material_id' => $copy?->id,
            'assessment_id' => $assessment->id,
            'title' => $assessment->purpose ?: 'Evaluación',
            'type' => 'assessment',
            'assessment_type' => $assessment->type->value,
            'tags' => [],
            'format' => null,
            'group' => ['id' => $assessment->group->id, 'name' => $assessment->group->name],
            'owner_name' => $assessment->teacher?->name,
            'is_mine' => $mine,
            'catalog_status' => $copy?->catalog_status?->value,
            'published_at' => null,
            'published_by_name' => null,
            'curricular_items' => $assessment->curricularItems->map(fn (CurricularItem $item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name])->values(),
            'created_at' => $assessment->created_at?->toIso8601String(),
            'administered_at' => $assessment->administered_at?->toDateString(),
            'can' => [
                'publish' => $mine && ! $published,
                'remove_from_catalog' => $copy !== null && $user->can('removeFromCatalog', $copy),
                'delete' => false,
            ],
        ];
    }
}
