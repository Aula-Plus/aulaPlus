<?php

namespace App\Contracts;

use App\Notifications\LogNotifier;

/**
 * Notifies interested parties that a tracked status changed (accommodation
 * approved/rejected, barrier↔accommodation validated, …). The only
 * implementation for now is {@see LogNotifier}; swapping
 * in a real channel (email/push) later only requires a new implementation of
 * this interface, bound in a service provider — callers never change.
 */
interface StatusChangeNotifier
{
    /**
     * @param  array<string, mixed>  $context  Event metadata only — IDs and
     *                                         types, never PII or clinical
     *                                         content (CLAUDE.md security
     *                                         rules 2 and 11).
     */
    public function notify(string $event, array $context): void;
}
