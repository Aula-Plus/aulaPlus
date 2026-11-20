<?php

namespace App\Http\Controllers;

use App\Enums\LevelRegime;
use App\Http\Requests\Onboarding\RequestRegimeCorrectionRequest;
use App\Models\EducationLevel;
use App\Models\OnboardingLogEntry;
use App\Models\OnboardingSource;
use App\Models\SchoolLevelRegime;
use App\Models\SchoolOnboarding;
use App\Models\SupportMessage;
use App\Notifications\SupportMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Screen 29 «Puesta en marcha» (ClickUp 1dfd3mc4bh8): Dirección reads how the
 * school's information came in — loaded by the Aula+ team with the
 * `app:onboarding-*` commands, never editable here — and asks for a regime
 * correction through the same path as «Ayuda y sugerencias».
 */
class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $school = $request->user()->school;
        $this->authorize('viewOnboarding', $school);

        $onboarding = SchoolOnboarding::query()->first();
        $regimes = SchoolLevelRegime::query()->get()->keyBy('education_level_id');

        return response()->json(['data' => [
            'import_completed_on' => $onboarding?->import_completed_on?->toDateString(),
            'regime_configured_on' => $onboarding?->regime_configured_on?->toDateString(),
            'opened_on' => $onboarding?->opened_on?->toDateString(),
            'offering_confirmed_on' => $onboarding?->offering_confirmed_on?->toDateString(),
            'contact' => $onboarding?->contact_name !== null || $onboarding?->contact_channel !== null
                ? ['name' => $onboarding->contact_name, 'channel' => $onboarding->contact_channel]
                : null,
            // Every level, loaded or not, so a missing regime is visible.
            // Ciclo Básico and Bachillerato are «habilitado» for every private
            // school (P23-INS-02): always that way, flagged `fixed` (the SPA
            // writes the Spanish line).
            'regimes' => EducationLevel::query()->orderBy('position')->get()->map(function (EducationLevel $level) use ($regimes): array {
                $loaded = $regimes->get($level->id);
                $fixed = LevelRegime::isFixedLevel($level->key);

                return [
                    'level_key' => $level->key,
                    'level_label' => $level->label,
                    'regime' => $fixed ? LevelRegime::Enabled->value : $loaded?->regime->value,
                    'fixed' => $fixed,
                    'note' => $loaded?->note,
                ];
            })->values(),
            'sources' => OnboardingSource::query()->orderBy('position')->orderBy('id')->get()->map(fn (OnboardingSource $source) => [
                'id' => $source->id,
                'source' => $source->source,
                'content' => $source->content,
                'format' => $source->format,
                'status' => $source->status->value,
            ])->values(),
            'log' => OnboardingLogEntry::query()->orderBy('happened_on')->orderBy('id')->get()->map(fn (OnboardingLogEntry $entry) => [
                'id' => $entry->id,
                'happened_on' => $entry->happened_on->toDateString(),
                'step' => $entry->step,
                'status' => $entry->status->value,
            ])->values(),
            'support_channels' => $this->supportChannels(),
        ]]);
    }

    public function requestRegimeCorrection(RequestRegimeCorrectionRequest $request): JsonResponse
    {
        $user = $request->user();

        $message = SupportMessage::create([
            'user_id' => $user->id,
            'kind' => SupportMessage::KIND_REGIME_CORRECTION,
            'message' => $request->validated('message'),
            'role' => $user->getRoleNames()->first() ?? 'unknown',
            'screen' => '/administracion/puesta-en-marcha',
        ]);

        $recipients = array_values(array_filter((array) config('services.support.notify_to')));
        if ($recipients !== []) {
            Notification::route('mail', $recipients)
                ->notify(new SupportMessageNotification($message, 'Puesta en marcha'));
        }

        return response()->json(['data' => [
            'id' => $message->id,
            'created_at' => $message->created_at?->toIso8601String(),
            'support_channels' => $this->supportChannels(),
        ]], 201);
    }

    /**
     * @return array{whatsapp: string|null, email: string|null}
     */
    protected function supportChannels(): array
    {
        return [
            'whatsapp' => config('services.support.whatsapp'),
            'email' => config('services.support.contact_email'),
        ];
    }
}
