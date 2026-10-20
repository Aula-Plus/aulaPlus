<?php

namespace App\Support;

/**
 * Whether the AI provider (Anthropic) is configured in this environment.
 *
 * An environment may run without AI for a while (no ANTHROPIC_API_KEY /
 * ANTHROPIC_MODEL yet). AI features check this before doing anything: the
 * request is refused with a clear 503 instead of queueing work that can only
 * fail, and a job that still runs records an error without calling the API.
 */
final class AiProvider
{
    /** Stored on a generation that ran while the provider was not configured. */
    public const NOT_CONFIGURED = 'The AI provider is not configured.';

    /** Message of the 503 returned when an AI feature is requested. */
    public const UNAVAILABLE = 'The AI assistant is not available right now.';

    public static function isConfigured(): bool
    {
        return filled(config('services.anthropic.key')) && filled(config('services.anthropic.model'));
    }
}
