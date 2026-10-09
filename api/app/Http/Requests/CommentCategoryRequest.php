<?php

namespace App\Http\Requests;

use App\Models\CommentCategory;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/rename a comment category (director only, via CommentCategoryPolicy).
 * Names are unique per school.
 */
class CommentCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof CommentCategory
            ? $this->user()->can('update', $category)
            : $this->user()->can('create', CommentCategory::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('comment_categories', 'name')
                    ->where('school_id', Tenancy::schoolId())
                    ->ignore($category instanceof CommentCategory ? $category->id : null),
            ],
        ];
    }
}
