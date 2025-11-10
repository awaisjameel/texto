<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Contracts\PollableMessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\TelnyxApiClient;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Log;

class TelnyxSender implements MessageSenderInterface, PollableMessageSenderInterface
{
    protected TelnyxApiClient $apiClient;

    public function __construct(protected array $config, ?TelnyxApiClient $apiClient = null)
    {
        $apiKey = $this->config['api_key'] ?? null;
        if (! $apiKey) {
            throw new TextoSendFailedException('Telnyx API key missing.');
        }
        $this->apiClient = $apiClient ?? new TelnyxApiClient($apiKey);
    }

    /**
     * @param  string[]  $mediaUrls
     */
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        $fromNumber = $from?->e164 ?? ($this->config['from_number'] ?? null);
        $profileId = $this->config['messaging_profile_id'] ?? null;
        if (! $fromNumber || ! $profileId) {
            throw new TextoSendFailedException('Telnyx from number or messaging_profile_id not configured.');
        }

        $webhookUrl = $metadata['webhook_url'] ?? null;
        $payload = [
            'to' => $to->e164,
            'from' => $fromNumber,
            'text' => $body,
            'messaging_profile_id' => $profileId,
        ];
        if (! empty($mediaUrls)) {
            $payload['media_urls'] = $mediaUrls;
        }
        if ($webhookUrl) {
            $payload['webhook_url'] = $webhookUrl;
        }

        try {
            $response = Retry::exponential(function () use ($payload) {
                return $this->apiClient->sendMessage($payload);
            }, (int) config('texto.retry.max_attempts', 3), (int) config('texto.retry.backoff_start_ms', 200));
        } catch (\Throwable $e) {
            Log::error('Texto Telnyx send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Telnyx send failed: '.$e->getMessage());
        }

        $data = $this->extractData($response['data'] ?? null);
        $providerId = null;
        $telnyxStatusRaw = null;
        $parts = null;
        $costAmount = null;
        $costCurrency = null;

        if ($data) {
            $providerId = $data['id'] ?? null;
            $telnyxStatusRaw = $this->extractRecipientStatus($data['to'] ?? null);
            $parts = $data['parts'] ?? null;
            $cost = $this->extractData($data['cost'] ?? null);
            if ($cost) {
                $costAmount = $cost['amount'] ?? null;
                $costCurrency = $cost['currency'] ?? 'USD';
            }
        }

        $statusEnum = StatusMapper::map(Driver::Telnyx, $telnyxStatusRaw, null);

        $augmentedMetadata = $metadata + array_filter([
            'telnyx_raw_status' => $telnyxStatusRaw,
            'telnyx_parts' => $parts,
            'telnyx_cost_amount' => $costAmount,
            'telnyx_cost_currency' => $costCurrency,
        ], fn ($v) => $v !== null);

        $result = new SentMessageResult(
            Driver::Telnyx,
            Direction::Sent,
            $to,
            PhoneNumber::fromString($fromNumber),
            $body,
            $mediaUrls,
            $augmentedMetadata,
            $statusEnum,
            $providerId,
        );
        Log::info('Texto Telnyx message send response parsed', [
            'provider_id' => $result->providerMessageId,
            'status' => $statusEnum->value,
            'parts' => $parts,
            'cost_amount' => $costAmount,
            'to' => $to->e164,
        ]);

        return $result;
    }

    /**
     * Poll latest status for a Telnyx message.
     */
    public function fetchStatus(string $providerMessageId, mixed ...$context): ?\Awaisjameel\Texto\Enums\MessageStatus
    {
        try {
            $resp = $this->apiClient->retrieveMessage($providerMessageId);
        } catch (\Throwable $e) {
            Log::warning('Telnyx fetchStatus failed', ['id' => $providerMessageId, 'error' => $e->getMessage()]);

            return null;
        }

        $data = $this->extractData($resp['data'] ?? null);
        if (! $data) {
            return null;
        }
        $raw = $this->extractRecipientStatus($data['to'] ?? null);
        if (! $raw) {
            return null;
        }

        return StatusMapper::map(Driver::Telnyx, $raw, null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractData(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return null;
    }

    private function extractRecipientStatus(mixed $recipients): ?string
    {
        $entries = $this->extractData($recipients);
        if (! $entries) {
            return null;
        }

        if (isset($entries['status']) && is_string($entries['status'])) {
            return $entries['status'];
        }

        $first = $entries[0] ?? null;
        if ($first === null) {
            return null;
        }

        $firstData = $this->extractData($first);
        if ($firstData && isset($firstData['status']) && is_string($firstData['status'])) {
            return $firstData['status'];
        }

        return null;
    }
}
