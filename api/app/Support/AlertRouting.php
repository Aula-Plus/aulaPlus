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
            default => [AlertRecipient::Teacher->value, AlertRecipient::Psychopedagogue->value],
        };
    }

    /**
     * @return list<string> AlertRecipient values
     */
    public static function recipientsFor(AlertType $type): array
    {
        $setting = AlertRoutingSetting::query()->where('type', $type->value)->first();

        return $setting?->recipients ?? self::defaultFor($type);
    }

    /**
     * Every alert type with its effective recipients, for the settings screen.
     *
     * @return list<array{type: string, recipients: list<string>, is_default: bool}>
     */
    public static function all(): array
    {
        $settings = AlertRoutingSetting::query()->get()->keyBy(fn ($setting) => $setting->type->value);

        return array_map(fn (AlertType $type) => [
            'type' => $type->value,
            'recipients' => $settings->get($type->value)?->recipients ?? self::defaultFor($type),
            'is_default' => ! $settings->has($type->value),
        ], self::configurableTypes());
    }

    /**
     * The alert types a school can route: every type that is still created.
     *
     * @return list<AlertType>
     */
    public static function configurableTypes(): array
    {
        return array_values(array_filter(
            AlertType::cases(),
            fn (AlertType $type) => $type !== AlertType::EscalatedOverdue,
        ));
    }
}
