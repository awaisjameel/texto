<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards application-defined endpoints with a shared secret (X-Texto-Secret header).
 *
 * Do not attach this to provider webhook routes: Twilio, Telnyx and Meta cannot send custom
 * headers, so the packaged routes rely on each provider's cryptographic signature instead.
 */
class VerifyTextoWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('texto.webhook.secret');
        if ($secret) {
            $provided = $request->header('X-Texto-Secret');
            if (! $provided || ! hash_equals($secret, $provided)) {
                return response('Invalid webhook secret', 403);
            }
        }

        return $next($request);
    }
}
