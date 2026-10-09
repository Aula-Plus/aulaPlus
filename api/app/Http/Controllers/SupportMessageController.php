<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportMessageRequest;
use App\Models\SupportMessage;
use App\Notifications\SupportMessageNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

/**
 * POST /api/v1/support-messages — stores the message and emails the Aula+
 * team. The sender's user, role and school are taken from the session, never
 * from the request body.
 */
class SupportMessageController extends Controller
{
    public function store(StoreSupportMessageRequest $request): JsonResponse
    {
        $user = $request->user();

        $message = SupportMessage::create([
            'user_id' => $user->id,
            'kind' => $request->validated('kind'),
            'message' => $request->validated('message'),
            'role' => $user->getRoleNames()->first() ?? 'unknown',
            'screen' => $request->validated('screen'),
        ]);

        $recipients = array_values(array_filter((array) config('services.support.notify_to')));

        if ($recipients !== []) {
            Notification::route('mail', $recipients)
                ->notify(new SupportMessageNotification($message));
        }

        return response()->json(['data' => ['id' => $message->id]], 201);
    }
}
