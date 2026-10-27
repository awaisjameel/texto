<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TelnyxMessagingApiInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TelnyxApiAuthException;
use Awaisjameel\Texto\Exceptions\TelnyxApiException;
use Awaisjameel\Texto\Exceptions\TelnyxApiNotFoundException;
use Awaisjameel\Texto\Exceptions\TelnyxApiRateLimitException;
use Awaisjameel\Texto\Exceptions\TelnyxApiValidationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class TelnyxMessagingApi implements TelnyxMessagingApiInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(protected string $apiKey, ?array $config = null)
    {
        $this->config = array_replace($config ?? Configuration::forDriver(Driver::Telnyx), ['api_key' => $apiKey]);
    }

    protected function http(): PendingRequest
    {
        return ProviderHttp::telnyx($this->config);
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
        $resp = $this->http()->post('messages', $payload);

        return $this->handle($resp, 'sendMessage', ['to' => $to]);
    }

    public function fetchMessage(string $messageId): array
    {
        $resp = $this->http()->get('messages/'.rawurlencode($messageId));

        return $this->handle($resp, 'fetchMessage', ['id' => $messageId]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    protected function handle(Response $response, string $action, array $ctx = []): array
    {
        try {
            $body = JsonResponse::object($response);
            if ($response->successful()) {
                $data = JsonResponse::map($body['data'] ?? $body);
                JsonResponse::requiredText($data, 'id');

                return $data;
            }
        } catch (\JsonException|\UnexpectedValueException $exception) {
            if ($response->successful()) {
                throw new TelnyxApiException('Telnyx returned an invalid message resource.', $response->status(), context: $ctx + ['action' => $action], previous: $exception);
            }
            $body = [];
        }
        $status = $response->status();
        $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
        $error = is_array($errors[0] ?? null) ? $errors[0] : [];
        $message = $error['detail'] ?? $body['message'] ?? null;
        $message = is_string($message) ? $message : 'Telnyx API error';
        $code = $error['code'] ?? null;
        $telnyxCode = is_string($code) || is_int($code) ? (string) $code : null;
        $exContext = $ctx + ['status' => $status, 'telnyx_code' => $telnyxCode, 'action' => $action, 'body' => $body];
        Log::warning('Telnyx Messaging API error', ['status' => $status, 'action' => $action, 'telnyx_code' => $telnyxCode]);
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
