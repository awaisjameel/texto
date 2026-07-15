<?php

use Awaisjameel\Texto\Contracts\MessageRepositoryInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Events\MessageReceived;
use Awaisjameel\Texto\Events\MessageStatusUpdated;
use Awaisjameel\Texto\Http\Middleware\RateLimitTextoWebhook;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\EmailWebhookHandler;
use Awaisjameel\Texto\Webhooks\TelnyxWebhookHandler;
use Awaisjameel\Texto\Webhooks\TwilioWebhookHandler;
use Awaisjameel\Texto\Webhooks\WhatsappWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$processWebhook = function (WebhookProcessingResult $result, MessageRepositoryInterface $repo): void {
    if ($result->direction === Direction::Received) {
        $message = $repo->storeInbound($result);
        if ($message->wasRecentlyCreated) {
            event(new MessageReceived($result));
        }

        return;
    }

    $updated = $repo->storeStatus($result);
    if ($updated) {
        event(new MessageStatusUpdated($result));
    }
};

Route::middleware([RateLimitTextoWebhook::class])
    ->post('/texto/webhook/twilio', function (Request $request, TwilioWebhookHandler $handler, MessageRepositoryInterface $repo) use ($processWebhook) {
        // A null result is an authentic event the handler chose to ignore (e.g. the
        // Conversations echo of a message this package itself sent).
        $result = $handler->handle($request);
        if ($result !== null) {
            $processWebhook($result, $repo);
        }

        return response()->json(['ok' => true]);
    })->name('texto.webhook.twilio');

Route::middleware([RateLimitTextoWebhook::class])
    ->post('/texto/webhook/telnyx', function (Request $request, TelnyxWebhookHandler $handler, MessageRepositoryInterface $repo) use ($processWebhook) {
        $processWebhook($handler->handle($request), $repo);

        return response()->json(['ok' => true]);
    })->name('texto.webhook.telnyx');

// Email has no universal provider signature scheme; the handler authenticates the request
// itself with the shared texto.webhook.secret (X-Texto-Secret header or ?secret= query param).
Route::middleware([RateLimitTextoWebhook::class])
    ->post('/texto/webhook/email', function (Request $request, EmailWebhookHandler $handler, MessageRepositoryInterface $repo) use ($processWebhook) {
        $processWebhook($handler->handle($request), $repo);

        return response()->json(['ok' => true]);
    })->name('texto.webhook.email');

// Meta validates subscriptions with this GET handshake. It cannot send Texto's shared secret.
Route::middleware([RateLimitTextoWebhook::class])
    ->get('/texto/webhook/whatsapp', function (Request $request) {
        $verifyToken = (string) config('texto.whatsapp.verify_token');
        if (
            $verifyToken !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($verifyToken, (string) $request->query('hub_verify_token'))
        ) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    })->name('texto.webhook.whatsapp.verify');

// Meta signs the raw request body with X-Hub-Signature-256; validation happens in the handler.
Route::middleware([RateLimitTextoWebhook::class])
    ->post('/texto/webhook/whatsapp', function (Request $request, WhatsappWebhookHandler $handler, MessageRepositoryInterface $repo) use ($processWebhook) {
        foreach ($handler->handleBatch($request) as $result) {
            $processWebhook($result, $repo);
        }

        return response()->json(['ok' => true]);
    })->name('texto.webhook.whatsapp');
