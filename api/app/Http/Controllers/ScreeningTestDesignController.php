<?php

namespace App\Http\Controllers;

use App\Actions\Screening\ComputeScreeningImpact;
use App\Enums\Role;
use App\Http\Requests\PreviewScreeningTestImpactRequest;
use App\Http\Requests\StoreScreeningTestDesignRequest;
use App\Http\Resources\ScreeningTestDesignResource;
use App\Models\ScreeningTestDesign;
use App\Models\ScreeningTestType;
use App\Models\User;
use App\Notifications\ScreeningDesignChangedNotification;
use App\Notifications\ScreeningDesignRevertedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Design authoring (screen 08). "Editing" a design means saving a new version:
 * each POST inserts a fresh ScreeningTestDesign that rules right away (no
 * approval step) and notifies Dirección, who can revert it. The screening
 * keeps the whole history; results keep the version they were read with.
 */
class ScreeningTestDesignController extends Controller
{
    /**
     * Every version of a screening's design, newest first, with who saved it
     * and whether it was reverted.
     */
    public function index(ScreeningTestType $type): AnonymousResourceCollection
    {
        $this->authorize('view', $type);

        $designs = $type->designs()
            ->with(['createdBy:id,name', 'revertedBy:id,name'])
            ->orderByDesc('id')
            ->get();

        return ScreeningTestDesignResource::collection($designs);
    }

    public function store(StoreScreeningTestDesignRequest $request, ScreeningTestType $type): JsonResponse
    {
        $design = ScreeningTestDesign::create([
            'screening_test_type_id' => $type->id,
            'bands' => $request->bands(),
            'reason' => $request->validated('reason'),
            'created_by_id' => $request->user()->id,
            'approved' => true,
        ]);
        $design->setRelation('type', $type);

        $directors = User::query()
            ->where('school_id', $type->school_id)
            ->whereKeyNot($request->user()->id)
            ->role(Role::Director->value)
            ->get();
        Notification::send($directors, new ScreeningDesignChangedNotification($design));

        return (new ScreeningTestDesignResource($design->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * "Volver atrás": Dirección reverts the design in force and the previous
     * one rules again. Only the design in force can be reverted, and only when
     * there is a previous one to go back to (422 otherwise).
     */
    public function revert(ScreeningTestDesign $design): ScreeningTestDesignResource
    {
        $this->authorize('revert', $design);

        $type = $design->type;

        DB::transaction(function () use ($design, $type): void {
            $ruled = ScreeningTestDesign::query()
                ->where('screening_test_type_id', $type->id)
                ->where('approved', true)
                ->whereNull('reverted_at')
                ->orderByDesc('id')
                ->limit(2)
                ->lockForUpdate()
                ->get();

            if ($ruled->first()?->id !== $design->id) {
                throw ValidationException::withMessages(['design' => 'Only the design in force can be reverted.']);
            }

            if ($ruled->count() < 2) {
                throw ValidationException::withMessages(['design' => 'There is no previous design to go back to.']);
            }

            $design->update([
                'reverted_at' => now(),
                'reverted_by_id' => auth()->id(),
            ]);
        });

        $author = $design->createdBy;
        if ($author !== null && $author->id !== auth()->id()) {
            $author->notify(new ScreeningDesignRevertedNotification($design));
        }

        return new ScreeningTestDesignResource($design->load(['createdBy:id,name', 'revertedBy:id,name']));
    }

    /**
     * "Ver cómo queda el grupo con este corte": counts per band with the
     * design in force and with the proposed bands, before saving.
     */
    public function impact(
        PreviewScreeningTestImpactRequest $request,
        ScreeningTestType $type,
        ComputeScreeningImpact $computeImpact,
    ): JsonResponse {
        return response()->json([
            'data' => $computeImpact($type, $request->bands(), $type->currentApprovedDesign()),
        ]);
    }
}
