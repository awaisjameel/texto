<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Log;
use Telnyx\Client as TelnyxClient;

class TelnyxSender implements MessageSenderInterface
{
    protected TelnyxClient $telnyxClient;

    public function __construct(protected array $config)
    {
        $apiKey = $this->config['api_key'] ?? null;
        if (! $apiKey) {
            throw new TextoSendFailedException('Telnyx API key missing.');
        }
        $this->telnyxClient = new TelnyxClient($apiKey);
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

        try {
            $response = Retry::exponential(function () use ($fromNumber, $to, $body, $profileId, $mediaUrls, $webhookUrl) {
                return $this->telnyxClient->messages->send(
                    to: $to->e164,
                    from: $fromNumber,
                    text: $body,
                    mediaURLs: $mediaUrls,
                    webhookURL: $webhookUrl,
                    messagingProfileID: $profileId
                );
            }, (int) config('texto.retry.max_attempts', 3), (int) config('texto.retry.backoff_start_ms', 200));

        } catch (\Throwable $e) {
            Log::error('Texto Telnyx send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Telnyx send failed: '.$e->getMessage());
        }

        // Telnyx SDK response shape: top-level object exposing ->data (stdClass) which holds id, to[], cost, etc.
        $data = null;
        if (is_object($response) && isset($response->data)) {
            $data = $response->data; // stdClass
        } elseif (is_array($response) && isset($response['data'])) {
            $data = (object) $response['data'];
        }

        $providerId = null;
        $telnyxStatusRaw = null;
        $parts = null;
        $costAmount = null;
        $costCurrency = null;

        if ($data) {
            $providerId = $data->id ?? null;
            // 'to' is an array of recipient objects; take first for status
            if (isset($data->to) && is_array($data->to) && isset($data->to[0]->status)) {
                $telnyxStatusRaw = $data->to[0]->status;
            }
            $parts = $data->parts ?? null;
            if (isset($data->cost) && isset($data->cost->amount)) {
                $costAmount = $data->cost->amount;
                $costCurrency = $data->cost->currency ?? 'USD';
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
    public function fetchStatus(string $providerMessageId): ?\Awaisjameel\Texto\Enums\MessageStatus
    {
        try {
            $resp = $this->telnyxClient->messages->retrieve($providerMessageId);
        } catch (\Throwable $e) {
            Log::warning('Telnyx fetchStatus failed', ['id' => $providerMessageId, 'error' => $e->getMessage()]);

            return null;
        }

        // Log::info('Texto Telnyx fetchStatus response received', ['id' => $providerMessageId, 'response' => $resp]);

        $data = null;
        if (is_object($resp) && isset($resp->data)) {
            $data = $resp->data;
        } elseif (is_array($resp) && isset($resp['data'])) {
            $data = (object) $resp['data'];
        }
        if (! $data) {
            return null;
        }
        $raw = null;
        if (isset($data->to) && is_array($data->to) && isset($data->to[0]->status)) {
            $raw = $data->to[0]->status;
        }
        if (! $raw) {
            return null;
        }

        // Log::info('Texto Telnyx fetchStatus parsed', ['id' => $providerMessageId, 'raw_status' => $raw]);

        return StatusMapper::map(Driver::Telnyx, $raw, null);
    }
}
