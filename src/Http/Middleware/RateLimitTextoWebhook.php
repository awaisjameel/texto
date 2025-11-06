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
        $limit = (int) config('texto.webhook.rate_limit', 60);
        $key = 'texto-webhook:'.sha1($request->ip().'|'.($request->path()));
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response('Too Many Requests', 429);
        }
        RateLimiter::hit($key, 60); // decay after 60 seconds

        return $next($request);
    }
}
