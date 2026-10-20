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
 * Only comments the asking user may see are counted — so psychopedagogy,
 * which reads every shared observation, counts all of them, and direction
 * counts the ones it can read.
 *
 * "Solo quien escribe" notes never count here, not even the asking user's own
 * (ClickUp 86e3qkwnv): they feed only the author's private signal
 * ({@see self::hasPrivateNotesSignal()}). Psychopedagogy's «Compartir con»
 * extracts are separate rows and never count either.
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
            ->where('author_only', false)
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

    /**
     * The private signal on a student file (ClickUp 86e3qkwnv): whether the
     * user's OWN "solo quien escribe" notes on this student reached the
     * school's trend threshold (same `comment_trend_min_count` within
     * `comment_trend_days`) in one same category. Any role gets it; nobody
     * else's notes count — so only the author can ever receive `true` — and a
     * note without a category counts nowhere. The mark never says which
     * category it was. A mark, never a notification.
     */
    public function hasPrivateNotesSignal(Student $student, User $user): bool
    {
        $school = School::query()->find($student->school_id);
        if ($school === null || $user->school_id !== $student->school_id) {
            return false;
        }

        $comments = $student->comments()
            ->with('categories:id')
            ->where('author_only', true)
            ->where('author_id', $user->id)
            ->where('created_at', '>=', now()->subDays($school->comment_trend_days))
            ->get();

        $byCategory = [];
        foreach ($comments as $comment) {
            foreach ($comment->categories as $category) {
                $byCategory[$category->id] = ($byCategory[$category->id] ?? 0) + 1;
            }
        }

        return $byCategory !== [] && max($byCategory) >= $school->comment_trend_min_count;
    }
}
