<?php

use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Events\MessageReceived;
use Awaisjameel\Texto\Events\MessageStatusUpdated;
use Awaisjameel\Texto\Http\Middleware\RateLimitTextoWebhook;
use Awaisjameel\Texto\Http\Middleware\VerifyTextoWebhookSecret;
use Awaisjameel\Texto\Repositories\EloquentMessageRepository;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\TelnyxWebhookHandler;
use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$processWebhook = function (WebhookProcessingResult $result, EloquentMessageRepository $repo): void {
    if ($result->direction === Direction::Received) {
        $repo->storeInbound($result);
        event(new MessageReceived($result));

        return;
    }

    $updated = $repo->storeStatus($result);
    if ($updated) {
        event(new MessageStatusUpdated($result));
    }
};

Route::middleware([VerifyTextoWebhookSecret::class, RateLimitTextoWebhook::class])
    ->post('/texto/webhook/twilio', function (Request $request, TwilioWebhookHandler $handler, EloquentMessageRepository $repo) use ($processWebhook) {
        $processWebhook($handler->handle($request), $repo);

        return response()->json(['ok' => true]);
    })->name('texto.webhook.twilio');

Route::middleware([VerifyTextoWebhookSecret::class, RateLimitTextoWebhook::class])
    ->post('/texto/webhook/telnyx', function (Request $request, TelnyxWebhookHandler $handler, EloquentMessageRepository $repo) use ($processWebhook) {
        $processWebhook($handler->handle($request), $repo);

        return response()->json(['ok' => true]);
    })->name('texto.webhook.telnyx');
