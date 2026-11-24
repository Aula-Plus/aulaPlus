<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One call to Anthropic's Messages API with a system and a user prompt,
 * returning the model's text or null on any failure (unreachable, non-2xx).
 * Never logs the prompts, the key or the response (CLAUDE.md rules 2 and 11).
 * Callers run it inside a queued job, never in the request.
 */
final class AnthropicMessages
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public static function text(string $system, string $user, int $maxTokens = 1024): ?string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout(60)
                ->retry(3, 1000, throw: false)
                ->post(self::ENDPOINT, [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $text = $response->json('content.0.text');

        return is_string($text) ? $text : null;
    }
}
