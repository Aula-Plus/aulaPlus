<?php

namespace App\Support;

use App\Enums\AlertRecipient;
use App\Enums\AlertType;
use App\Models\AlertRoutingSetting;

/**
 * Who each alert type reaches first, for the current school (ClickUp
 * 86e3jpzcv). Product defaults: performance alerts go first only to the
 * teacher of that subject; every other type goes to the teacher and
 * psychopedagogy at once. Direction never gets a new alert by default — the
 * school can change any of this from the alert settings screen.
 *
 * The escalated alert (ClickUp 86e3jpzdp) is no longer created (review of
 * 14/10: a missed deadline marks the same alert instead), so it is not
 * configurable any more and the settings screen no longer lists it.
 *
 * The «Señal de dificultad» alert (ClickUp 86e3qkwpn) never reaches teachers:
 * it is built from observations a teacher may not be allowed to read
 * (reserved scopes count too), so `teacher` is not an allowed recipient for
 * it — rejected when saving the routing ({@see self::allowedRecipients()}) and
 * stripped again when resolving recipients, whatever a stored row says. By
 * default it reaches psychopedagogy only.
 */
class AlertRouting
{
    /**
     * @return list<string> AlertRecipient values
     */
    public static function defaultFor(AlertType $type): array
    {
        return match ($type) {
            AlertType::Performance => [AlertRecipient::Teacher->value],
            // Legacy: no longer created, no role gets it.
            AlertType::EscalatedOverdue => [],
            AlertType::DifficultySignal => [AlertRecipient::Psychopedagogue->value],
            default => [AlertRecipient::Teacher->value, AlertRecipient::Psychopedagogue->value],
        };
    }

    /**
     * The recipients a school may choose for an alert type.
     *
     * @return list<string> AlertRecipient values
     */
    public static function allowedRecipients(AlertType $type): array
    {
        return self::allowsTeachers($type)
            ? AlertRecipient::values()
            : array_values(array_diff(AlertRecipient::values(), [AlertRecipient::Teacher->value]));
    }

    /**
     * Whether a teacher may ever receive (or be handed) an alert of this type.
     */
    public static function allowsTeachers(AlertType $type): bool
    {
        return $type !== AlertType::DifficultySignal;
    }

    /**
     * @return list<string> AlertRecipient values
     */
    public static function recipientsFor(AlertType $type): array
    {
        $setting = AlertRoutingSetting::query()->where('type', $type->value)->first();

        return self::onlyAllowed($type, $setting?->recipients ?? self::defaultFor($type));
    }

    /**
     * @param  array<int, string>  $recipients
     * @return list<string>
     */
    protected static function onlyAllowed(AlertType $type, array $recipients): array
    {
        return array_values(array_intersect($recipients, self::allowedRecipients($type)));
    }

    /**
     * The alert types the school can route: only the ones something actually
     * generates. `PlanningAttendance` is a placeholder nothing produces yet, so
     * the school can't configure recipients for an alert that never happens
     * (ClickUp 86e3qkyrn), and `EscalatedOverdue` is no longer created
     * (86e3jpzdp). Add a type here once its generator exists.
     *
     * @return list<AlertType>
     */
    public static function configurableTypes(): array
    {
        return [AlertType::Performance, AlertType::DifficultySignal];
    }

    /**
     * Every configurable alert type with its effective recipients, for the
     * settings screen. `allowed_recipients` tells the screen which options to
     * offer.
     *
     * @return list<array{type: string, recipients: list<string>, allowed_recipients: list<string>, is_default: bool}>
     */
    public static function all(): array
    {
        $settings = AlertRoutingSetting::query()->get()->keyBy(fn ($setting) => $setting->type->value);

        return array_map(fn (AlertType $type) => [
            'type' => $type->value,
            'recipients' => self::onlyAllowed($type, $settings->get($type->value)?->recipients ?? self::defaultFor($type)),
            'allowed_recipients' => self::allowedRecipients($type),
            'is_default' => ! $settings->has($type->value),
        ], self::configurableTypes());
    }
}
