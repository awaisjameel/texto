<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitTextoWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('texto.webhook.rate_limit', 300);

        // Key per webhook endpoint (path) rather than client IP. Provider traffic arrives through a
        // load balancer, so IP-keying would either collapse every request into a single bucket (when
        // TrustProxies is unset) or fan out per-edge unpredictably. A per-endpoint bucket is a stable
        // DoS safety cap that pairs with the signature verification guarding these routes.
        $key = 'texto-webhook:'.sha1($request->path());
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response('Too Many Requests', 429);
        }
        RateLimiter::hit($key, 60); // decay after 60 seconds

        return $next($request);
    }
}
