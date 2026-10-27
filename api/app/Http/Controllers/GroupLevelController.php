<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGroupLevelRequest;
use App\Http\Requests\UpdateGroupLevelSettingRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A group's level (ClickUp 86e3t4qjh). Changing it applies forward only:
 * screenings already applied keep the level they were applied with (snapshot
 * on ScreeningTestApplication). Every change is recorded by Auditable on Group
 * — who, when, and from which level to which.
 */
class GroupLevelController extends Controller
{
    /**
     * PUT /groups/{group}/level
     */
    public function update(UpdateGroupLevelRequest $request, Group $group): GroupResource
    {
        $group->update([
            'education_level_id' => $request->validated('education_level_id'),
            'school_grade_id' => $request->validated('school_grade_id'),
        ]);

        return new GroupResource($group->load('teachers'));
    }

    /**
     * GET /group-level-setting — whether psychopedagogy may set group levels,
     * and whether the viewer may. Any staff member reads it.
     */
    public function showSetting(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settingPayload($request, $request->user()->school)]);
    }

    /**
     * PUT /group-level-setting — direction only.
     */
    public function updateSetting(UpdateGroupLevelSettingRequest $request): JsonResponse
    {
        $school = School::query()->findOrFail($request->user()->school_id);
        $school->psychopedagogue_can_set_group_level = $request->boolean('psychopedagogue_can_set_group_level');
        $school->save();

        return response()->json(['data' => $this->settingPayload($request, $school->refresh())]);
    }

    /**
     * @return array<string, bool>
     */
    protected function settingPayload(Request $request, School $school): array
    {
        $user = $request->user()->setRelation('school', $school);

        return [
            'psychopedagogue_can_set_group_level' => $school->psychopedagogue_can_set_group_level,
            // The role half of GroupPolicy::setLevel, for the UI only.
            'can_set_level' => $user->can('setLevel', (new Group)->forceFill(['school_id' => $school->id])),
        ];
    }
}
