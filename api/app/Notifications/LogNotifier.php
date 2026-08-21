<?php

namespace App\Notifications;

use App\Contracts\StatusChangeNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Default {@see StatusChangeNotifier} implementation: just logs the event.
 * No email/push channel exists yet — this is the seam that will be swapped
 * for a real one later, without touching any caller.
 *
 * CLAUDE.md security rules 2 and 11: only the event type and the IDs passed
 * in $context reach the log — callers must never pass PII, student names or
 * clinical content in $context.
 */
class LogNotifier implements StatusChangeNotifier
{
    public function notify(string $event, array $context): void
    {
        Log::info("status-change.{$event}", $context);
    }
}
