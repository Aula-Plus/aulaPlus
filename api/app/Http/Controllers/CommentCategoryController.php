<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommentCategoryRequest;
use App\Models\CommentCategory;
use App\Models\Student;
use App\Support\StudentFileHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * School-editable comment categories. Reads: any staff member (the comment
 * form needs them). Writes: director only (CommentCategoryRequest/Policy).
 * Deleting a category leaves its comments without it ("Sin categoría"), and
 * that change is recorded in each affected student's change history.
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

        // Removing a category changes the categories of every observation
        // that had it (the pivot rows cascade). On a student's file that is a
        // modification of the observation, recorded in its change history
        // (ClickUp 86e3qkwmc) as before → after, under whoever deleted it.
        $affected = $category->comments()
            ->where('commentable_type', Student::class)
            ->with('categories')
            ->get();

        DB::transaction(function () use ($category, $affected): void {
            $category->delete();

            foreach ($affected as $comment) {
                $before = StudentFileHistory::categoryNamesOf($comment);
                $comment->setRelation('categories', $comment->categories->except([$category->id]));

                StudentFileHistory::recordCommentCategoriesChange($comment, $before, StudentFileHistory::categoryNamesOf($comment));
            }
        });

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
