<?php

use Awaisjameel\Texto\Events\MessageReceived;
use Awaisjameel\Texto\Events\MessageStatusUpdated;
use Awaisjameel\Texto\Http\Middleware\RateLimitTextoWebhook;
use Awaisjameel\Texto\Http\Middleware\VerifyTextoWebhookSecret;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Awaisjameel\Texto\Webhooks\TelnyxStatusWebhookHandler;
use Awaisjameel\Texto\Webhooks\TelnyxWebhookHandler;
use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware([VerifyTextoWebhookSecret::class, RateLimitTextoWebhook::class])
    ->post('/texto/webhook/twilio', function (Request $request, TwilioWebhookHandler $handler, EloquentMessageRepository $repo) {
        $result = $handler->handle($request);
        // Branch based on direction/status presence
        if ($result->direction === \Awaisjameel\Texto\Enums\Direction::Received) {
            $repo->storeInbound($result);
            event(new MessageReceived($result));
        } else {
            $updated = $repo->storeStatus($result);
            if ($updated) {
                event(new MessageStatusUpdated($result));
            }
        }

        return response()->json(['ok' => true]);
    })->name('texto.webhook.twilio');

Route::middleware([VerifyTextoWebhookSecret::class, RateLimitTextoWebhook::class])
    ->post('/texto/webhook/telnyx', function (Request $request, TelnyxWebhookHandler $handler, EloquentMessageRepository $repo) {
        $result = $handler->handle($request);
        $repo->storeInbound($result);
        event(new MessageReceived($result));

        return response()->json(['ok' => true]);
    })->name('texto.webhook.telnyx');

// Twilio status route deprecated; keep backwards compatibility optional redirect or reuse same closure if needed.

Route::middleware([VerifyTextoWebhookSecret::class, RateLimitTextoWebhook::class])
    ->post('/texto/webhook/telnyx/status', function (Request $request, TelnyxStatusWebhookHandler $handler, EloquentMessageRepository $repo) {
        $result = $handler->handle($request);
        $updated = $repo->storeStatus($result);
        if ($updated) {
            event(new MessageStatusUpdated($result));
        }

        return response()->json(['ok' => true]);
    })->name('texto.webhook.telnyx.status');
