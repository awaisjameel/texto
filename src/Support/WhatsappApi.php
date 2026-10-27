<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\WhatsappApiInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\WhatsappApiAuthException;
use Awaisjameel\Texto\Exceptions\WhatsappApiException;
use Awaisjameel\Texto\Exceptions\WhatsappApiNotFoundException;
use Awaisjameel\Texto\Exceptions\WhatsappApiRateLimitException;
use Awaisjameel\Texto\Exceptions\WhatsappApiValidationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class WhatsappApi implements WhatsappApiInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(protected string $accessToken, protected string $phoneNumberId, ?array $config = null)
    {
        $this->config = array_replace($config ?? Configuration::forDriver(Driver::Whatsapp), ['access_token' => $accessToken]);
    }

    public function sendMessage(array $payload): array
    {
        $response = $this->client()->post(rawurlencode($this->phoneNumberId).'/messages', $payload);
        $result = $this->handle(
            $response,
            'sendMessage',
            ['phone_number_id' => $this->phoneNumberId, 'to' => $payload['to'] ?? null],
        );
        $messages = $result['messages'] ?? null;
        try {
            $first = JsonResponse::map(is_array($messages) ? ($messages[0] ?? null) : null);
            JsonResponse::requiredText($first, 'id');
        } catch (\UnexpectedValueException $exception) {
            throw new WhatsappApiException('WhatsApp returned no message ID.', $response->status(), previous: $exception);
        }

        return $result;
    }

    public function getMediaUrl(string $mediaId): array
    {
        return $this->handle($this->client()->get(rawurlencode($mediaId)), 'getMediaUrl', ['media_id' => $mediaId]);
    }

    public function markAsRead(string $wamid): array
    {
        return $this->handle(
            $this->client()->post(rawurlencode($this->phoneNumberId).'/messages', [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $wamid,
            ]),
            'markAsRead',
            ['phone_number_id' => $this->phoneNumberId, 'message_id' => $wamid],
        );
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function handle(Response $response, string $action, array $context = []): array
    {
        try {
            $body = JsonResponse::object($response);
            if ($response->successful()) {
                return $body;
            }
        } catch (\JsonException|\UnexpectedValueException $exception) {
            if ($response->successful()) {
                throw new WhatsappApiException('WhatsApp returned an invalid JSON object.', $response->status(), context: $context + ['action' => $action], previous: $exception);
            }
            $body = [];
        }

        $status = $response->status();
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $graphCode = isset($error['code']) ? (string) $error['code'] : null;
        $graphSubcode = isset($error['error_subcode']) ? (string) $error['error_subcode'] : null;
        $details = $error['error_data']['details'] ?? null;
        $message = is_string($details) && $details !== ''
            ? $details
            : (is_string($error['message'] ?? null) ? $error['message'] : 'WhatsApp Graph API error');
        $exceptionContext = $context + [
            'action' => $action,
            'status' => $status,
            'graph_code' => $graphCode,
            'graph_subcode' => $graphSubcode,
            'body' => $body,
        ];
        Log::warning('WhatsApp Graph API error', ['action' => $action, 'status' => $status, 'graph_code' => $graphCode, 'graph_subcode' => $graphSubcode]);

        if ($status === 401 || $status === 403 || $graphCode === '190') {
            throw new WhatsappApiAuthException($message, $status, $graphCode, $graphSubcode, $exceptionContext);
        }
        if ($status === 404) {
            throw new WhatsappApiNotFoundException($message, $status, $graphCode, $graphSubcode, $exceptionContext);
        }
        // 4/80007 app+WABA call limits, 130429 throughput, 131048 spam rate limit, 131056 pair rate limit.
        if ($status === 429 || in_array($graphCode, ['4', '80007', '130429', '131048', '131056'], true)) {
            throw new WhatsappApiRateLimitException($message, $status, $graphCode, $graphSubcode, $exceptionContext);
        }
        if ($status === 400 && in_array($graphCode, ['100', '131008', '131009'], true)) {
            throw new WhatsappApiValidationException($message, $status, $graphCode, $graphSubcode, $exceptionContext);
        }

        throw new WhatsappApiException($message, $status, $graphCode, $graphSubcode, $exceptionContext);
    }

    private function client(): PendingRequest
    {
        // Keep the adapter usable when constructed directly, while the macro still supplies base URL/timeouts.
        return ProviderHttp::whatsapp($this->config);
    }
}
