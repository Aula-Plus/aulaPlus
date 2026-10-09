<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A school-editable category a comment can be tagged with (several allowed,
 * optional). Replaces the old comment "tone". Deleting one just leaves its
 * comments without that category (pivot rows cascade).
 */
#[Fillable(['name', 'position'])]
class CommentCategory extends Model
{
    use BelongsToSchool;

    /** Every school starts with these; each can rename, add or remove. */
    public const DEFAULT_NAMES = ['Académico', 'Conductual', 'Social', 'Emocional', 'Familiar', 'Otro'];

    public function comments(): BelongsToMany
    {
        return $this->belongsToMany(Comment::class, 'comment_comment_category');
    }

    /**
     * Idempotently create the default categories for a school (explicit
     * school_id: callable from migrations/console with no tenant set).
     */
    public static function seedDefaults(int $schoolId): void
    {
        foreach (self::DEFAULT_NAMES as $position => $name) {
            $exists = static::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                (new static)->forceFill([
                    'school_id' => $schoolId,
                    'name' => $name,
                    'position' => $position,
                ])->save();
            }
        }
    }
}
