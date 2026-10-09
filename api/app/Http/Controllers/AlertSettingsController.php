<?php

namespace App\Http\Controllers;

use App\Enums\AlertCondition;
use App\Enums\AlertType;
use App\Http\Requests\AlertRuleRequest;
use App\Http\Requests\UpdateAlertRoutingRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertRoutingSetting;
use App\Models\AlertRule;
use App\Support\AlertRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The school's alert settings (ClickUp 86e3jpzcv; documento vivo screen 11):
 * the sustained-low-performance conditions ("el umbral es una perilla, no un
 * cálculo nuestro") and who each alert type reaches first. Direction and
 * psychopedagogy only — see AlertRulePolicy.
 */
class AlertSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('viewAny', AlertRule::class);

        return response()->json(['data' => [
            'rules' => AlertRuleResource::collection(AlertRule::query()->orderBy('created_at')->get()),
            'routing' => AlertRouting::all(),
        ]]);
    }

    public function storeRule(AlertRuleRequest $request): JsonResponse
    {
        $rule = AlertRule::create($this->normalized($request->validated()));

        return (new AlertRuleResource($rule))->response()->setStatusCode(201);
    }

    public function updateRule(AlertRuleRequest $request, AlertRule $alertRule): AlertRuleResource
    {
        $alertRule->update($this->normalized($request->validated(), $alertRule));

        return new AlertRuleResource($alertRule);
    }

    public function destroyRule(AlertRule $alertRule): Response
    {
        $this->authorize('delete', $alertRule);

        $alertRule->delete();

        return response()->noContent();
    }

    public function updateRouting(UpdateAlertRoutingRequest $request, AlertType $type): JsonResponse
    {
        AlertRoutingSetting::query()->updateOrCreate(
            ['type' => $type->value],
            ['recipients' => array_values($request->validated('recipients'))],
        );

        return response()->json(['data' => AlertRouting::all()]);
    }

    /**
     * Clear the parameter the chosen condition doesn't use, so a rule never
     * carries a stale `period_days` on a "consecutive" condition (or vice
     * versa).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalized(array $data, ?AlertRule $existing = null): array
    {
        $condition = AlertCondition::from($data['condition'] ?? $existing->condition->value);

        if ($condition === AlertCondition::ConsecutiveBelow) {
            $data['period_days'] = null;
        } else {
            $data['consecutive_count'] = null;
        }

        return $data;
    }
}
