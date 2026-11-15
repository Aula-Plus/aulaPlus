<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Student;
use App\Models\StudentAnnualReview;
use App\Support\SchoolTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * «Ajustes y PTP» › Revisión anual (screen 06, ClickUp 1dfd3mc4bgc).
 *
 * Lists the students with accommodations in force (PTP joins when it exists)
 * and the date and who of their latest review; «Marcar revisado hoy» records
 * today's date and who for the current school year. Nothing is switched off by
 * a missing review (P06-DEC-04): a student without this year's review raises a
 * single «Revisión anual: N alumnos» task in psychopedagogy's Inicio
 * (P01-DEC-06), served by `pending`.
 *
 * The school year is the calendar year (school time), the same one the
 * group_student pivot uses. Psychopedagogy only (StudentAnnualReviewPolicy).
 */
class StudentAnnualReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', StudentAnnualReview::class);
        $year = SchoolTime::today()->year;

        $students = $this->studentsToReview()
            ->with([
                'groups' => fn ($groups) => $groups->wherePivot('school_year', $year)->select('groups.id', 'groups.name'),
            ])
            ->withCount(['accommodations as accommodations_in_force_count' => fn (Builder $accommodations) => $accommodations->effective()])
            ->orderBy('full_name')
            ->get(['id', 'school_id', 'full_name']);

        $latest = StudentAnnualReview::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->with('reviewedBy:id,name')
            ->orderBy('school_year')
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'data' => $students->map(function (Student $student) use ($latest, $year): array {
                $review = $latest->get($student->id);
                $group = $student->groups->first();

                return [
                    'student' => ['id' => $student->id, 'name' => $student->full_name],
                    'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
                    'accommodations_in_force_count' => $student->accommodations_in_force_count,
                    'last_review' => $review ? [
                        'reviewed_on' => $review->reviewed_on->toDateString(),
                        'reviewed_by_name' => $review->reviewedBy?->name,
                        'school_year' => $review->school_year,
                    ] : null,
                    'reviewed_this_year' => $review?->school_year === $year,
                ];
            })->values(),
            'meta' => ['school_year' => $year],
        ]);
    }

    /**
     * How many students still lack this year's review: the one row of the
     * Inicio task (P01-DEC-06), never a list.
     */
    public function pending(): JsonResponse
    {
        $this->authorize('viewAny', StudentAnnualReview::class);
        $year = SchoolTime::today()->year;

        $count = $this->studentsToReview()
            ->whereDoesntHave('annualReviews', fn (Builder $reviews) => $reviews->where('school_year', $year))
            ->count();

        return response()->json(['data' => ['count' => $count, 'school_year' => $year]]);
    }

    public function store(Student $student): JsonResponse
    {
        $this->authorize('create', [StudentAnnualReview::class, $student]);
        $today = SchoolTime::today();

        $review = StudentAnnualReview::query()->updateOrCreate(
            ['student_id' => $student->id, 'school_year' => $today->year],
            ['reviewed_on' => $today->toDateString(), 'reviewed_by_id' => auth()->id()],
        );

        return response()->json(['data' => [
            'student_id' => $student->id,
            'reviewed_on' => $review->reviewed_on->toDateString(),
            'reviewed_by_name' => auth()->user()->name,
            'school_year' => $review->school_year,
        ]], 201);
    }

    /**
     * Students of the school with at least one accommodation in force.
     *
     * @return Builder<Student>
     */
    protected function studentsToReview(): Builder
    {
        return Student::query()->whereHas(
            'accommodations',
            fn (Builder $accommodations) => $accommodations->effective(),
        );
    }
}
