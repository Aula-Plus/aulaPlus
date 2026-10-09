<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Comment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

/**
 * Flags the recurrence of one comment category on a student: at least
 * `comment_trend_min_count` comments of the same category within the last
 * `comment_trend_days` days (both set by the school). It is a mark to look at,
 * never an alert — no owner, no deadline — and only psychopedagogy/direction
 * get it (the count anchors teachers, so it is never computed for them).
 * Only comments the asking user may see are counted.
 */
class CommentTrendDetector
{
    /**
     * @return list<array{category_id: int, category_name: string, count: int, authors: int, days: int}>
     */
    public function forStudent(Student $student, User $user): array
    {
        if (! $user->hasAnyRole(Role::schoolWideValues())) {
            return [];
        }

        $school = School::query()->find($student->school_id);
        if ($school === null) {
            return [];
        }

        $comments = $student->comments()
            ->with('categories')
            ->where('created_at', '>=', now()->subDays($school->comment_trend_days))
            ->visibleToRole($user)
            ->get();

        $byCategory = [];
        foreach ($comments as $comment) {
            foreach ($comment->categories as $category) {
                $byCategory[$category->id]['name'] = $category->name;
                $byCategory[$category->id]['comments'][$comment->id] = $comment->author_id;
            }
        }

        $trends = [];
        foreach ($byCategory as $categoryId => $info) {
            if (count($info['comments']) >= $school->comment_trend_min_count) {
                $trends[] = [
                    'category_id' => (int) $categoryId,
                    'category_name' => $info['name'],
                    'count' => count($info['comments']),
                    'authors' => count(array_unique($info['comments'])),
                    'days' => $school->comment_trend_days,
                ];
            }
        }

        return $trends;
    }
}
