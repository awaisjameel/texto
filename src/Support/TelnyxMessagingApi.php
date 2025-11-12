<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TelnyxMessagingApiInterface;
use Awaisjameel\Texto\Exceptions\TelnyxApiAuthException;
use Awaisjameel\Texto\Exceptions\TelnyxApiException;
use Awaisjameel\Texto\Exceptions\TelnyxApiNotFoundException;
use Awaisjameel\Texto\Exceptions\TelnyxApiRateLimitException;
use Awaisjameel\Texto\Exceptions\TelnyxApiValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelnyxMessagingApi implements TelnyxMessagingApiInterface
{
    public function __construct(protected string $apiKey)
    {
    }

    public function sendMessage(string $to, string $from, string $body, array $mediaUrls = [], array $options = []): array
    {
        $payload = [
            'to' => $to,
            'from' => $from,
            'text' => $body,
        ];
        foreach ($options as $k => $v) {
            $payload[$k] = $v;
        }
        if ($mediaUrls) {
            $payload['media_urls'] = $mediaUrls;
        }
        $resp = Http::telnyx()->post('messages', $payload);
        return $this->handle($resp, 'sendMessage', ['to' => $to]);
    }

    public function fetchMessage(string $messageId): array
    {
        $resp = Http::telnyx()->get('messages/' . $messageId);
        return $this->handle($resp, 'fetchMessage', ['id' => $messageId]);
    }

    protected function handle($response, string $action, array $ctx = []): array
    {
        if ($response->successful()) {
            $json = $response->json();
            $data = is_array($json) ? ($json['data'] ?? $json) : [];
            return is_array($data) ? $data : [];
        }
        $status = $response->status();
        $body = $response->json() ?? [];
        $message = $body['errors'][0]['detail'] ?? ($body['message'] ?? 'Telnyx API error');
        $telnyxCode = $body['errors'][0]['code'] ?? null;
        $exContext = $ctx + ['status' => $status, 'telnyx_code' => $telnyxCode, 'action' => $action, 'body' => $body];
        Log::warning('Telnyx Messaging API error', $exContext);
        if ($status === 401 || $status === 403) {
            throw new TelnyxApiAuthException($message, $status, (string) $telnyxCode, $exContext);
        }
        if ($status === 404) {
            throw new TelnyxApiNotFoundException($message, $status, (string) $telnyxCode, $exContext);
        }
        if ($status === 429) {
            throw new TelnyxApiRateLimitException($message, $status, (string) $telnyxCode, $exContext);
        }
        if ($status === 400 || $status === 422) {
            throw new TelnyxApiValidationException($message, $status, (string) $telnyxCode, $exContext);
        }
        throw new TelnyxApiException($message, $status, (string) $telnyxCode, $exContext);
    }
}
