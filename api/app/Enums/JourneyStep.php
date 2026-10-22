<?php

namespace App\Enums;

/**
 * One recorded step of a creation journey (ClickUp 86e3qkxtc, screen 28 of
 * the living document): the trail behind a teacher's creation attempts and
 * confirmed creations, shown to Dirección only. Spanish labels live in the
 * frontend.
 *
 * - `form_opened` / `filling_started` are the only steps the browser may
 *   report (POST /usage/journey-steps); every other step is recorded
 *   server-side by the action that actually happened.
 * - `generation_requested` is the AI attempt: one per journey. Any later
 *   request inside the same journey (a retry or a change request) is
 *   `regeneration_requested` and never adds an attempt.
 * - `generation_succeeded` means a proposal was received and is pending the
 *   teacher's confirmation — never "abandoned".
 */
enum JourneyStep: string
{
    case FormOpened = 'form_opened';
    case FillingStarted = 'filling_started';
    case GenerationRequested = 'generation_requested';
    case RegenerationRequested = 'regeneration_requested';
    case GenerationSucceeded = 'generation_succeeded';
    case GenerationFailed = 'generation_failed';
    case CreationConfirmed = 'creation_confirmed';
    case DraftDiscarded = 'draft_discarded';

    /**
     * The steps the browser is allowed to report.
     *
     * @return list<string>
     */
    public static function clientReportableValues(): array
    {
        return [self::FormOpened->value, self::FillingStarted->value];
    }

    /** The usage_events.event_type a step row is stored under. */
    public function eventType(): string
    {
        return 'journey.'.$this->value;
    }
}
