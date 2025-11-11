<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface;
use Awaisjameel\Texto\Exceptions\TwilioApiAuthException;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Awaisjameel\Texto\Exceptions\TwilioApiNotFoundException;
use Awaisjameel\Texto\Exceptions\TwilioApiRateLimitException;
use Awaisjameel\Texto\Exceptions\TwilioApiValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwilioMessagingApi implements TwilioMessagingApiInterface
{
    public function __construct(protected string $accountSid, protected string $authToken) {}

    public function sendMessage(string $to, string $from, ?string $body, array $mediaUrls = [], array $options = []): array
    {
        $endpoint = '/Accounts/'.$this->accountSid.'/Messages.json';
        $payload = [
            'To' => $to,
            'From' => $from,
        ] + ($body !== null ? ['Body' => $body] : []);
        foreach ($options as $k => $v) {
            // Allow passing already correct Twilio param names (e.g. StatusCallback)
            $payload[$k] = $v;
        }

        // Build form body manually to support repeated MediaUrl keys
        $form = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        foreach ($mediaUrls as $m) {
            $form .= '&'.'MediaUrl='.rawurlencode($m);
        }

        $response = Http::twilio('messaging')
            ->withBasicAuth($this->accountSid, $this->authToken) // macro already sets but explicit for clarity
            ->withBody($form, 'application/x-www-form-urlencoded')
            ->post($endpoint);

        if ($response->successful()) {
            return $response->json();
        }

        $this->throwForResponse($response, 'sendMessage');

        return []; // unreachable
    }

    public function fetchMessage(string $messageSid): array
    {
        $endpoint = '/Accounts/'.$this->accountSid.'/Messages/'.$messageSid.'.json';
        $response = Http::twilio('messaging')->get($endpoint);
        if ($response->successful()) {
            return $response->json();
        }
        $this->throwForResponse($response, 'fetchMessage', ['sid' => $messageSid]);

        return [];
    }

    protected function throwForResponse($response, string $action, array $ctx = []): void
    {
        $status = $response->status();
        $body = $response->json() ?? [];
        $code = $body['code'] ?? null; // Twilio error code
        $message = $body['message'] ?? ($body['detail'] ?? 'Twilio API error');
        $exContext = $ctx + ['status' => $status, 'twilio_code' => $code, 'action' => $action, 'body' => $body];
        Log::warning('Twilio Messaging API error', $exContext);
        if ($status === 401 || $status === 403) {
            throw new TwilioApiAuthException($message, $status, (string) $code, $exContext);
        }
        if ($status === 404) {
            throw new TwilioApiNotFoundException($message, $status, (string) $code, $exContext);
        }
        if ($status === 429) {
            throw new TwilioApiRateLimitException($message, $status, (string) $code, $exContext);
        }
        if ($status === 400) {
            throw new TwilioApiValidationException($message, $status, (string) $code, $exContext);
        }
        throw new TwilioApiException($message, $status, (string) $code, $exContext);
    }
}
