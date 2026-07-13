<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\WhatsappApiInterface;
use Awaisjameel\Texto\Exceptions\WhatsappApiAuthException;
use Awaisjameel\Texto\Exceptions\WhatsappApiException;
use Awaisjameel\Texto\Exceptions\WhatsappApiNotFoundException;
use Awaisjameel\Texto\Exceptions\WhatsappApiRateLimitException;
use Awaisjameel\Texto\Exceptions\WhatsappApiValidationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappApi implements WhatsappApiInterface
{
    public function __construct(protected string $accessToken, protected string $phoneNumberId) {}

    public function sendMessage(array $payload): array
    {
        return $this->handle(
            $this->client()->post($this->phoneNumberId.'/messages', $payload),
            'sendMessage',
            ['phone_number_id' => $this->phoneNumberId, 'to' => $payload['to'] ?? null],
        );
    }

    public function getMediaUrl(string $mediaId): array
    {
        return $this->handle($this->client()->get($mediaId), 'getMediaUrl', ['media_id' => $mediaId]);
    }

    public function markAsRead(string $wamid): array
    {
        return $this->handle(
            $this->client()->post($this->phoneNumberId.'/messages', [
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
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];
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
        Log::warning('WhatsApp Graph API error', $exceptionContext);

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
        return Http::whatsapp()->withToken($this->accessToken);
    }
}
