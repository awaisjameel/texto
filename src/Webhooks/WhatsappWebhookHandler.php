<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Webhooks;

use Awaisjameel\Texto\Contracts\WebhookHandlerInterface;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Exceptions\TextoWebhookValidationException;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\WebhookProcessingResult;
use Awaisjameel\Texto\Webhooks\Concerns\ValidatesMetaSignature;
use Illuminate\Http\Request;

class WhatsappWebhookHandler implements WebhookHandlerInterface
{
    use ValidatesMetaSignature;

    /** @var array<string, mixed> */
    protected array $config;

    /** @param null|array<string, mixed> $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('texto.whatsapp', []);
    }

    public function handle(Request $request): WebhookProcessingResult
    {
        $results = $this->handleBatch($request);
        if ($results === []) {
            throw new TextoWebhookValidationException('WhatsApp webhook contains no processable message or status.');
        }

        return $results[0];
    }

    /** @return WebhookProcessingResult[] */
    public function handleBatch(Request $request): array
    {
        $skip = config('texto.testing.skip_webhook_validation', false) && app()->environment('testing');
        if (! $skip) {
            $this->assertValidMetaSignature($request, $this->config);
        }

        $payload = $request->json()->all();
        $entries = $payload['entry'] ?? null;
        if (! is_array($entries)) {
            throw new TextoWebhookValidationException('WhatsApp webhook payload missing entries.');
        }

        $results = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_array($entry['changes'] ?? null)) {
                throw new TextoWebhookValidationException('WhatsApp webhook entry missing changes.');
            }
            foreach ($entry['changes'] as $change) {
                if (! is_array($change)) {
                    throw new TextoWebhookValidationException('WhatsApp webhook change is malformed.');
                }
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    throw new TextoWebhookValidationException('WhatsApp messages change missing value.');
                }
                $displayNumber = $this->arrayValue($value, 'metadata')['display_phone_number'] ?? null;
                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message)) {
                        throw new TextoWebhookValidationException('WhatsApp inbound message is malformed.');
                    }
                    $results[] = $this->mapInbound($message, $value, $displayNumber);
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (! is_array($status)) {
                        throw new TextoWebhookValidationException('WhatsApp message status is malformed.');
                    }
                    $results[] = $this->mapStatus($status);
                }
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $message
     * @param  array<string, mixed>  $value
     */
    private function mapInbound(array $message, array $value, mixed $displayNumber): WebhookProcessingResult
    {
        $from = $this->normalizeWaNumber($message['from'] ?? null, 'from');
        $to = $this->normalizeWaNumber($displayNumber, 'display_phone_number');
        $providerMessageId = $message['id'] ?? null;
        if (! is_string($providerMessageId) || $providerMessageId === '') {
            throw new TextoWebhookValidationException('WhatsApp inbound message missing id.');
        }
        $type = isset($message['type']) ? (string) $message['type'] : '';
        if ($type === '') {
            throw new TextoWebhookValidationException('WhatsApp inbound message missing type.');
        }

        [$body, $media, $extra] = $this->messageContent($message, $type);
        $contact = is_array($value['contacts'][0] ?? null) ? $value['contacts'][0] : [];
        $metadata = array_filter([
            'event_type' => 'message',
            'message_type' => $type,
            'profile_name' => $this->arrayValue($contact, 'profile')['name'] ?? null,
            'wa_id' => $contact['wa_id'] ?? null,
            'context' => is_array($message['context'] ?? null) ? $message['context'] : null,
        ], static fn ($item) => $item !== null) + $extra;

        return WebhookProcessingResult::inbound(
            Driver::Whatsapp,
            $from,
            $to,
            $body,
            $media,
            $metadata,
            $providerMessageId,
        );
    }

    /** @param array<string, mixed> $status */
    private function mapStatus(array $status): WebhookProcessingResult
    {
        $providerMessageId = $status['id'] ?? null;
        if (! is_string($providerMessageId) || $providerMessageId === '') {
            throw new TextoWebhookValidationException('WhatsApp message status missing id.');
        }
        $rawStatus = isset($status['status']) ? (string) $status['status'] : null;
        $metadata = array_filter([
            'raw_status' => $rawStatus,
            'recipient_id' => $status['recipient_id'] ?? null,
            'conversation' => is_array($status['conversation'] ?? null) ? $status['conversation'] : null,
            'pricing' => is_array($status['pricing'] ?? null) ? $status['pricing'] : null,
            'errors' => is_array($status['errors'] ?? null) ? $status['errors'] : null,
        ], static fn ($item) => $item !== null);

        return WebhookProcessingResult::status(
            Driver::Whatsapp,
            $providerMessageId,
            StatusMapper::map(Driver::Whatsapp, $rawStatus),
            $metadata,
        );
    }

    /** @param array<string, mixed> $message
     * @return array{0: ?string, 1: string[], 2: array<string, mixed>}
     */
    private function messageContent(array $message, string $type): array
    {
        if ($type === 'text') {
            $text = $this->arrayValue($message, 'text');

            return [is_string($text['body'] ?? null) ? $text['body'] : null, [], []];
        }
        if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            $mediaData = is_array($message[$type] ?? null) ? $message[$type] : [];
            $id = $mediaData['id'] ?? null;
            $media = is_string($id) && $id !== '' ? ['whatsapp-media://'.$id] : [];
            $mediaMetadata = array_filter([
                'id' => $id,
                'mime_type' => $mediaData['mime_type'] ?? null,
                'sha256' => $mediaData['sha256'] ?? null,
                'filename' => $mediaData['filename'] ?? null,
            ], static fn ($item) => $item !== null);

            return [is_string($mediaData['caption'] ?? null) ? $mediaData['caption'] : null, $media, $mediaMetadata ? ['media' => $mediaMetadata] : []];
        }
        if ($type === 'button') {
            $button = $this->arrayValue($message, 'button');

            return [is_string($button['text'] ?? null) ? $button['text'] : null, [], []];
        }
        if ($type === 'interactive') {
            $interactive = is_array($message['interactive'] ?? null) ? $message['interactive'] : [];
            $body = $this->arrayValue($interactive, 'button_reply')['title']
                ?? $this->arrayValue($interactive, 'list_reply')['title']
                ?? null;

            return [is_string($body) ? $body : null, [], []];
        }
        if ($type === 'reaction') {
            $reaction = is_array($message['reaction'] ?? null) ? $message['reaction'] : [];

            return [is_string($reaction['emoji'] ?? null) ? $reaction['emoji'] : null, [], array_filter([
                'reaction_to' => $reaction['message_id'] ?? null,
            ], static fn ($item) => $item !== null)];
        }

        return [null, [], ['raw_message' => $message]];
    }

    private function normalizeWaNumber(mixed $raw, string $field): PhoneNumber
    {
        if (! is_string($raw) || $raw === '') {
            throw new TextoWebhookValidationException("WhatsApp webhook missing {$field} phone number.");
        }
        try {
            return PhoneNumber::fromString(str_starts_with($raw, '+') ? $raw : '+'.$raw);
        } catch (\Throwable $e) {
            throw new TextoWebhookValidationException("Invalid WhatsApp {$field} phone number: ".$e->getMessage(), 0, $e);
        }
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function arrayValue(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
