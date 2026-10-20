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
 * school can change any of this from the alert settings screen. An escalated
 * alert (ClickUp 86e3jpzdp) always reaches the two people involved; the roles
 * configured here are added on top.
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
            // Always reaches the two people involved (who handed it off and who
            // had to resolve it) individually; no role gets it by default.
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
     * Every alert type with its effective recipients, for the settings screen.
     *
     * `allowed_recipients` tells the screen which options to offer.
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
        ], AlertType::cases());
    }
}
