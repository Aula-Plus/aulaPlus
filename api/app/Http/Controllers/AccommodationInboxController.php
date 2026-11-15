<?php

namespace App\Http\Controllers;

use App\Enums\AccommodationReviewStatus;
use App\Enums\ProposalStatus;
use App\Http\Requests\AccommodationInboxRequest;
use App\Http\Resources\AccommodationResource;
use App\Models\Accommodation;
use App\Models\AccommodationProposal;
use App\Models\Student;
use App\Support\SchoolTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * «Ajustes y PTP» › Ajustes (screen 06, ClickUp 1dfd3mc4bgc): psychopedagogy's
 * own inbox with every accommodation of the school — those waiting to be
 * confirmed and those in force — next to its student and current group.
 *
 * Only psychopedagogy reaches it (AccommodationPolicy::viewInbox, enforced in
 * the FormRequest): a list of students with their accommodations and barriers,
 * out of each student's file, is the roster the screen's red line keeps away
 * from teachers. The actions themselves reuse the existing endpoints and
 * policies (confirm/return/reject a direction proposal, edit, switch off).
 *
 * Each row carries an `inbox_status`, derived from the stored fields:
 * - pending / returned / rejected: a direction proposal in that state.
 *   Withdrawn proposals are direction's own record and are left out.
 * - in_force: confirmed (or registered by psychopedagogy) and effective.
 * - inactive («De baja»): confirmed but switched off.
 *
 * `report_proposals` are the reports whose AI-read accommodations are still
 * waiting: only the report's upload date and how many, never the file name
 * (P06-DEC-14) nor the proposals' content — they are reviewed in the file.
 */
class AccommodationInboxController extends Controller
{
    public const STATUSES = ['in_force', 'pending', 'returned', 'rejected', 'inactive'];

    public function index(AccommodationInboxRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $year = SchoolTime::today()->year;

        $query = Accommodation::query()
            ->where(fn (Builder $review) => $review
                ->whereNull('review_status')
                ->orWhere('review_status', '!=', AccommodationReviewStatus::Withdrawn->value))
            ->with([
                'subjects',
                'proposedBy:id,name',
                'reviewedBy:id,name',
                'createdBy:id,name',
                'latestReviewEvent',
                'barriers:id,description',
                'student' => fn ($student) => $student->select('id', 'school_id', 'full_name'),
                'student.groups' => fn ($groups) => $groups->wherePivot('school_year', $year)->select('groups.id', 'groups.name'),
            ])
            ->orderBy('student_id')
            ->orderBy('id');

        if (isset($filters['category'])) {
            $filters['category'] === 'none'
                ? $query->whereNull('category')
                : $query->where('category', $filters['category']);
        }

        if (isset($filters['group_id'])) {
            $query->whereHas('student.groups', fn (Builder $groups) => $groups
                ->where('groups.id', $filters['group_id'])
                ->where('group_student.school_year', $year));
        }

        $rows = $query->get()
            ->map(fn (Accommodation $accommodation): array => [
                ...(new AccommodationResource($accommodation))->resolve($request),
                'inbox_status' => $this->status($accommodation),
                'student' => ['id' => $accommodation->student?->id, 'name' => $accommodation->student?->full_name],
                'group' => $this->group($accommodation->student),
                'barriers' => $accommodation->barriers
                    ->map(fn ($barrier): array => ['id' => $barrier->id, 'description' => $barrier->description])
                    ->values(),
                'confirmed_by_name' => $this->confirmedBy($accommodation),
            ])
            ->when(isset($filters['status']), fn ($rows) => $rows->where('inbox_status', $filters['status']))
            ->values();

        return response()->json([
            'data' => $rows,
            'report_proposals' => $this->reportProposals($filters, $year),
        ]);
    }

    protected function status(Accommodation $accommodation): string
    {
        return match ($accommodation->review_status) {
            AccommodationReviewStatus::Pending => 'pending',
            AccommodationReviewStatus::Returned => 'returned',
            AccommodationReviewStatus::Rejected => 'rejected',
            default => $accommodation->isEffective() ? 'in_force' : 'inactive',
        };
    }

    /**
     * «Confirmó»: who confirmed a direction proposal, or who registered it
     * when psychopedagogy created it already in force.
     */
    protected function confirmedBy(Accommodation $accommodation): ?string
    {
        if ($accommodation->review_status === AccommodationReviewStatus::Confirmed) {
            return $accommodation->reviewedBy?->name;
        }

        return $accommodation->review_status === null ? $accommodation->createdBy?->name : null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function group(?Student $student): ?array
    {
        $group = $student?->groups->first();

        return $group ? ['id' => $group->id, 'name' => $group->name] : null;
    }

    /**
     * Reports with AI-read accommodations still waiting for psychopedagogy.
     * Shown only while the status filter allows pending rows, and narrowed by
     * the group filter; the category filter does not apply (a report groups
     * proposals of several types).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    protected function reportProposals(array $filters, int $year): array
    {
        if (isset($filters['status']) && $filters['status'] !== 'pending') {
            return [];
        }

        $query = AccommodationProposal::query()
            ->where('status', ProposalStatus::Pending->value)
            ->with([
                'reportDocument:id,created_at',
                'student' => fn ($student) => $student->select('id', 'school_id', 'full_name'),
                'student.groups' => fn ($groups) => $groups->wherePivot('school_year', $year)->select('groups.id', 'groups.name'),
            ]);

        if (isset($filters['group_id'])) {
            $query->whereHas('student.groups', fn (Builder $groups) => $groups
                ->where('groups.id', $filters['group_id'])
                ->where('group_student.school_year', $year));
        }

        return $query->get()
            ->groupBy('report_document_id')
            ->map(function ($proposals) {
                $first = $proposals->first();

                return [
                    'report_id' => $first->report_document_id,
                    'student' => ['id' => $first->student?->id, 'name' => $first->student?->full_name],
                    'group' => $this->group($first->student),
                    'uploaded_on' => $first->reportDocument?->created_at
                        ?->setTimezone(SchoolTime::timezone())->toDateString(),
                    'pending_count' => $proposals->count(),
                ];
            })
            ->values()
            ->all();
    }
}
