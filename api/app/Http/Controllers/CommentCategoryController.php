<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentCategoryRequest;
use App\Models\CommentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * School-editable comment categories. Reads: any staff member (the comment
 * form needs them). Writes: director only (CommentCategoryRequest/Policy).
 * Deleting a category leaves its comments without it ("Sin categoría").
 */
class CommentCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', CommentCategory::class);

        return response()->json(['data' => CommentCategory::query()
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (CommentCategory $category) => $this->present($category))]);
    }

    public function store(CommentCategoryRequest $request): JsonResponse
    {
        $category = CommentCategory::create([
            'name' => $request->validated('name'),
            'position' => (int) CommentCategory::query()->max('position') + 1,
        ]);

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(CommentCategoryRequest $request, CommentCategory $category): JsonResponse
    {
        $category->update(['name' => $request->validated('name')]);

        return response()->json(['data' => $this->present($category)]);
    }

    public function destroy(CommentCategory $category): Response
    {
        $this->authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }

    /**
     * @return array{id: int, name: string, position: int}
     */
    protected function present(CommentCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'position' => $category->position];
    }
}
