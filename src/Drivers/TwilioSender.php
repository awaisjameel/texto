<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\AddressInterface;
use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Contracts\PollableMessageSenderInterface;
use Awaisjameel\Texto\Contracts\TwilioContentApiInterface;
use Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface;
use Awaisjameel\Texto\Contracts\TwilioDeliveryReceiptsApiInterface;
use Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Awaisjameel\Texto\Exceptions\TwilioApiRateLimitException;
use Awaisjameel\Texto\Support\Configuration;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\TwilioContentApi;
use Awaisjameel\Texto\Support\TwilioConversationsApi;
use Awaisjameel\Texto\Support\TwilioMessagingApi;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TwilioSender implements MessageSenderInterface, PollableMessageSenderInterface
{
    use Concerns\ExpectsPhoneNumbers;

    protected const TEMPLATE_BODY_SEGMENTS = 5;

    protected const TEMPLATE_BODY_SEGMENT_CHARS = 100;

    protected const TEMPLATE_BODY_MAX_CHARS = self::TEMPLATE_BODY_SEGMENTS * self::TEMPLATE_BODY_SEGMENT_CHARS;

    protected TwilioMessagingApiInterface $messagingApi;

    protected TwilioConversationsApiInterface $conversationsApi;

    protected TwilioContentApiInterface $contentApi;

    protected ?string $smsTemplateSid = null;

    protected ?string $mmsTemplateSid = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        ?TwilioMessagingApiInterface $messagingApi = null,
        ?TwilioConversationsApiInterface $conversationsApi = null,
        ?TwilioContentApiInterface $contentApi = null,
    ) {
        $sid = $config['account_sid'] ?? null;
        $token = $config['auth_token'] ?? null;
        if (! $sid || ! $token) {
            throw new TextoSendFailedException('Twilio credentials missing.');
        }
        $this->messagingApi = $messagingApi ?? new TwilioMessagingApi($sid, $token, $config);
        $this->conversationsApi = $conversationsApi ?? new TwilioConversationsApi($sid, $token, $config);
        $this->contentApi = $contentApi ?? new TwilioContentApi($sid, $token, $config);
    }

    /**
     * Send an SMS/MMS message via Twilio.
     *
     * Supports both Conversations API (with templates) and classic Messages API.
     *
     * @param  AddressInterface  $to  Recipient phone number
     * @param  string  $body  Message body text
     * @param  AddressInterface|null  $from  Sender phone number
     * @param  string[]  $mediaUrls  Array of media URLs for MMS
     * @param  array<string, mixed>  $metadata  Additional metadata
     *
     * @throws TextoSendFailedException
     */
    public function send(AddressInterface $to, string $body, ?AddressInterface $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {
        $to = $this->assertPhoneNumber($to, 'Twilio', 'to');
        $from = $from !== null ? $this->assertPhoneNumber($from, 'Twilio', 'from') : null;

        $fromNumber = $from ? $from->e164 : ($this->config['from_number'] ?? null);
        if (! $fromNumber) {
            throw new TextoSendFailedException('Twilio from number not configured.');
        }

        $useConversations = ($this->config['use_conversations'] ?? false) === true;

        if ($useConversations) {
            return $this->sendViaConversations($to, $body, $fromNumber, $mediaUrls, $metadata);
        }

        $webhookUrl = $metadata['webhook_url'] ?? null;
        $options = is_string($webhookUrl) && $webhookUrl !== '' ? ['StatusCallback' => $webhookUrl] : [];

        try {
            $raw = Retry::exponential(
                function () use ($to, $fromNumber, $body, $mediaUrls, $options) {
                    return $this->messagingApi->sendMessage(
                        $to->e164,
                        $fromNumber,
                        $body,
                        $mediaUrls,
                        $options
                    );
                },
                (int) config('texto.retry.max_attempts', 3),
                (int) config('texto.retry.backoff_start_ms', 200),
                fn (\Throwable $error): bool => $error instanceof TwilioApiRateLimitException,
            );
        } catch (TwilioApiException $e) {
            Log::error('Texto Twilio (legacy REST) send failed', ['error' => $e->getMessage(), 'status' => $e->status, 'code' => $e->twilioCode]);
            throw new TextoSendFailedException('Twilio send failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Texto Twilio (legacy REST unexpected) send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Twilio send failed: '.$e->getMessage());
        }

        $providerSid = $raw['sid'] ?? $raw['message_sid'] ?? null;
        $result = new SentMessageResult(
            Driver::Twilio,
            Direction::Sent,
            $to,
            PhoneNumber::fromString($fromNumber),
            $body,
            $mediaUrls,
            $metadata,
            MessageStatus::Sent,
            $providerSid,
        );
        Log::info('Texto Twilio message sent (legacy)', ['sid' => $result->providerMessageId, 'to' => $to->e164]);

        return $result;

    }

    /**
     * Send message via Twilio Conversations API with content templates.
     *
     * @param  PhoneNumber  $to  Recipient phone number
     * @param  string  $body  Message body text
     * @param  string  $fromNumber  Sender phone number (E.164)
     * @param  string[]  $mediaUrls  Array of media URLs for MMS
     * @param  array<string, mixed>  $metadata  Additional metadata
     *
     * @throws TextoSendFailedException
     */
    protected function sendViaConversations(PhoneNumber $to, string $body, string $fromNumber, array $mediaUrls, array $metadata): SentMessageResult
    {
        if (count($mediaUrls) > 1) {
            throw new TextoSendFailedException('Conversations sends support one media URL; send attachments separately or explicitly select the classic Messages API.');
        }
        $mediaUrl = $mediaUrls[0] ?? null;
        if ($mediaUrl !== null) {
            $this->mediaPath($mediaUrl);
        }
        $this->initializeTemplates($mediaUrl !== null);

        $contentSid = $mediaUrl ? $this->mmsTemplateSid : $this->smsTemplateSid;
        if ($mediaUrl !== null && $contentSid === null) {
            throw new TextoSendFailedException('Conversations media sends require a valid MMS content template; body-only fallback would discard the attachment.');
        }
        $bodyFitsTemplate = mb_strlen($body) <= self::TEMPLATE_BODY_MAX_CHARS;
        if ($mediaUrl !== null && ! $bodyFitsTemplate) {
            throw new TextoSendFailedException(sprintf(
                'Twilio MMS via content template supports bodies up to %d characters (got %d); shorten the body or send the text separately.',
                self::TEMPLATE_BODY_MAX_CHARS,
                mb_strlen($body),
            ));
        }
        $useContentTemplate = $contentSid !== null && $bodyFitsTemplate;

        // Reuse the conversation from a previous send to this (from, to) pair when one is
        // cached: the full setup flow costs 4-6 API calls per send, while a reused
        // conversation only needs the message POST. Participants and the webhook were
        // already configured when the conversation was first used.
        $cacheKey = $this->conversationCacheKey($to->e164, $fromNumber);
        $cacheTtl = (int) ($this->config['conversation_cache_ttl'] ?? 604800);
        $cachedSid = $cacheTtl > 0 ? Cache::get($cacheKey) : null;
        if (is_string($cachedSid) && $cachedSid !== '') {
            try {
                // A per-send webhook override must take effect even on a reused conversation.
                $webhookSid = isset($metadata['webhook_url'])
                    ? $this->attachConversationWebhook($cachedSid, (string) $metadata['webhook_url'])
                    : null;

                return $this->deliverConversationMessage($cachedSid, true, $webhookSid, $to, $body, $fromNumber, $mediaUrls, $metadata, $useContentTemplate, $contentSid);
            } catch (TwilioApiException|TextoSendFailedException $e) {
                $providerError = $e instanceof TwilioApiException ? $e : $e->getPrevious();
                if (! $providerError instanceof TwilioApiException || $providerError->status !== 404) {
                    throw $e;
                }
                // The cached conversation may have been closed or deleted since it was
                // stored; drop it and run the full setup flow once before giving up.
                Cache::forget($cacheKey);
                Log::info('Cached Twilio Conversation unusable; creating a fresh one.', ['conversation_sid' => $cachedSid, 'to' => $to->e164, 'error' => $e->getMessage()]);
            }
        }

        [$conversationSid, $reusedConversation] = $this->resolveConversation($to, $fromNumber);

        // Metadata webhook_url overrides the configured default.
        $webhookUrl = $metadata['webhook_url'] ?? ($this->config['conversation_webhook_url'] ?? null);
        $webhookSid = $webhookUrl ? $this->attachConversationWebhook($conversationSid, $webhookUrl) : null;

        try {
            $result = $this->deliverConversationMessage($conversationSid, $reusedConversation, $webhookSid, $to, $body, $fromNumber, $mediaUrls, $metadata, $useContentTemplate, $contentSid);
        } catch (\Throwable $e) {
            $providerError = $e instanceof TwilioApiException ? $e : $e->getPrevious();
            if (! $reusedConversation && $providerError instanceof TwilioApiException && $providerError->status >= 400 && $providerError->status < 500 && $providerError->status !== 408) {
                // Nothing was delivered into the conversation we just created; delete it
                // (best-effort) so a failed send does not leave an orphan behind.
                $this->deleteConversationSilently($conversationSid);
            }
            throw $e;
        }

        if ($cacheTtl > 0) {
            Cache::put($cacheKey, $conversationSid, now()->addSeconds($cacheTtl));
        }

        return $result;
    }

    /**
     * Create the conversation for a recipient, recovering via Twilio's duplicate-binding
     * error (code 50416) when the participant already belongs to one.
     *
     * @return array{string, bool} The conversation SID and whether an existing conversation was reused.
     *
     * @throws TextoSendFailedException
     */
    protected function resolveConversation(PhoneNumber $to, string $fromNumber): array
    {
        $prefix = $this->config['conversation_prefix'] ?? 'Texto';
        $friendlyName = $prefix.'-'.$to->e164.'-'.bin2hex(random_bytes(4));
        try {
            $newConversation = $this->conversationsApi->createConversation($friendlyName);
        } catch (TwilioApiException $e) {
            Log::error('Failed to create Twilio Conversation', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Unable to create Twilio Conversation: '.$e->getMessage());
        }

        $conversationSid = $newConversation['sid'] ?? null;
        if (! $conversationSid) {
            throw new TextoSendFailedException('Twilio Conversation creation returned no SID.');
        }

        $reusedConversation = false;

        // On a duplicate participant (Twilio code 50416) parse the existing conversation SID
        // from the error and reuse that conversation instead of the one we just created.
        try {
            $this->conversationsApi->addParticipant($conversationSid, $to->e164, $fromNumber);
        } catch (TwilioApiException $e) {
            if ((int) ($e->twilioCode ?? 0) === 50416) {
                $existingSid = $this->parseConversationSidFromError($e->getMessage());
                if ($existingSid && $existingSid !== $conversationSid) {
                    $this->conversationsApi->deleteConversation($conversationSid);
                    $conversationSid = $existingSid;
                    $reusedConversation = true;
                    Log::info('Reusing existing Twilio Conversation for participant.', ['conversation_sid' => $conversationSid, 'to' => $to->e164]);
                } else {
                    Log::warning('Duplicate participant error without parsable conversation SID; continuing with new conversation.', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                }
            } else {
                $this->deleteConversationSilently($conversationSid);
                Log::error('Failed to add initial participant to Twilio Conversation', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Unable to add participant to Twilio Conversation: '.$e->getMessage());
            }
        }

        $this->addParticipantSilently($conversationSid, $fromNumber, $fromNumber);

        return [$conversationSid, $reusedConversation];
    }

    /**
     * Send the message into a resolved conversation and build the send result.
     *
     * @param  string[]  $mediaUrls
     * @param  array<string, mixed>  $metadata
     *
     * @throws TextoSendFailedException
     */
    protected function deliverConversationMessage(
        string $conversationSid,
        bool $reusedConversation,
        ?string $webhookSid,
        PhoneNumber $to,
        string $body,
        string $fromNumber,
        array $mediaUrls,
        array $metadata,
        bool $useContentTemplate,
        ?string $contentSid,
    ): SentMessageResult {
        $messageData = [
            'Author' => $fromNumber,
            ...($useContentTemplate ? [
                'ContentSid' => $contentSid,
                'ContentVariables' => json_encode($this->prepareContentVariables($body, $mediaUrls[0] ?? null)),
            ] : ['Body' => $body]),
        ];

        try {
            $sentRaw = Retry::exponential(
                fn () => $this->conversationsApi->sendConversationMessage($conversationSid, $messageData),
                (int) config('texto.retry.max_attempts', 3),
                (int) config('texto.retry.backoff_start_ms', 200),
                fn (\Throwable $error): bool => $error instanceof TwilioApiRateLimitException,
            );
        } catch (TwilioApiException $e) {
            if ($mediaUrls === [] && $useContentTemplate && $e->status === 404) { // Text-only template not found: safe body fallback.
                Log::warning('Template send failed, retrying with body fallback.', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                $messageDataFallback = ['Author' => $fromNumber, 'Body' => $body];
                $sentRaw = $this->conversationsApi->sendConversationMessage($conversationSid, $messageDataFallback);
            } else {
                Log::error('Twilio Conversation message send failed', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Twilio Conversation send failed: '.$e->getMessage(), 0, $e);
            }
        }

        $providerSid = $sentRaw['sid'] ?? $sentRaw['message_sid'] ?? null;
        $result = new SentMessageResult(
            Driver::Twilio,
            Direction::Sent,
            $to,
            PhoneNumber::fromString($fromNumber),
            $body,
            $mediaUrls,
            $metadata + [
                'conversation_sid' => $conversationSid,
                'conversation_reused' => $reusedConversation,
                ...($webhookSid ? ['conversation_webhook_sid' => $webhookSid] : []),
            ],
            MessageStatus::Sent,
            $providerSid,
        );
        Log::info('Texto Twilio conversation message sent', [
            'message_sid' => $result->providerMessageId,
            'conversation_sid' => $conversationSid,
            'to' => $to->e164,
            'template_used' => $useContentTemplate,
            'conversation_reused' => $reusedConversation,
        ]);

        return $result;
    }

    protected function conversationCacheKey(string $toE164, string $fromNumber): string
    {
        // Scoped by account SID so per-send credential overrides never reuse another tenant's conversation.
        return 'texto:twilio:conversation:'.($this->config['account_sid'] ?? '').':'.$fromNumber.':'.$toE164;
    }

    protected function deleteConversationSilently(string $conversationSid): void
    {
        try {
            $this->conversationsApi->deleteConversation($conversationSid);
        } catch (\Throwable $e) {
            Log::debug('Failed to delete Twilio Conversation (non-fatal)', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
        }
    }

    protected function initializeTemplates(bool $media = false): void
    {
        $cached = $media ? $this->mmsTemplateSid : $this->smsTemplateSid;
        if ($cached !== null) {
            return;
        }
        $settings = new Configuration($this->config);
        $sid = $settings->text($media ? 'mms_template_sid' : 'sms_template_sid');
        if ($sid === '') {
            $friendlyName = $settings->text($media ? 'mms_template_friendly_name' : 'sms_template_friendly_name', $media ? 'texto_mms_template' : 'texto_sms_template');
            try {
                $sid = $this->contentApi->ensureTemplate($friendlyName, fn () => $media ? $this->defaultMmsTemplateDefinition($friendlyName) : $this->defaultSmsTemplateDefinition($friendlyName));
            } catch (\Throwable $exception) {
                Log::warning('Twilio content template initialization failed.', ['media' => $media]);

                return;
            }
        }
        if ($media) {
            $this->mmsTemplateSid = $sid;
        } else {
            $this->smsTemplateSid = $sid;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultSmsTemplateDefinition(string $friendlyName): array
    {
        return [
            'friendly_name' => $friendlyName,
            'language' => 'en_US',
            'variables' => [
                'message_body_1' => '',
                'message_body_2' => '',
                'message_body_3' => '',
                'message_body_4' => '',
                'message_body_5' => '',
            ],
            'types' => [
                'twilio/text' => [
                    'body' => '{{message_body_1}}{{message_body_2}}{{message_body_3}}{{message_body_4}}{{message_body_5}}',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultMmsTemplateDefinition(string $friendlyName): array
    {
        return [
            'friendly_name' => $friendlyName,
            'language' => 'en_US',
            'variables' => [
                'media_path' => '',
                'message_body_1' => '',
                'message_body_2' => '',
                'message_body_3' => '',
                'message_body_4' => '',
                'message_body_5' => '',
            ],
            'types' => [
                'twilio/media' => [
                    'body' => '{{message_body_1}}{{message_body_2}}{{message_body_3}}{{message_body_4}}{{message_body_5}}',
                    // config('app.url'), not env(), so this works when config is cached
                    'media' => [$this->mediaBaseUrl().'/{{media_path}}'],
                ],
            ],
        ];
    }

    /**
     * Split message body into template variables (TEMPLATE_BODY_SEGMENTS segments of
     * TEMPLATE_BODY_SEGMENT_CHARS characters each). Callers must ensure the body fits within
     * TEMPLATE_BODY_MAX_CHARS; the multibyte split keeps UTF-8 characters intact at segment
     * boundaries (a byte-based split could corrupt them).
     *
     * @param  string  $body  Message body text
     * @param  string|null  $mediaUrl  Optional media URL for MMS templates
     * @return array<string, string> Template variables array
     */
    protected function prepareContentVariables(string $body, ?string $mediaUrl = null): array
    {
        $parts = mb_str_split($body, self::TEMPLATE_BODY_SEGMENT_CHARS);
        $vars = [];
        for ($i = 1; $i <= self::TEMPLATE_BODY_SEGMENTS; $i++) {
            $vars['message_body_'.$i] = $parts[$i - 1] ?? '';
        }
        if ($mediaUrl) {
            $vars['media_path'] = $this->mediaPath($mediaUrl);
        }

        return $vars;
    }

    private function mediaBaseUrl(): string
    {
        $base = $this->config['mms_media_base_url'] ?? config('app.url');
        if (! is_string($base) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string(parse_url($base, PHP_URL_HOST)) || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) {
            throw new TextoSendFailedException('Configure a valid HTTP(S) MMS media base URL without a query or fragment.');
        }

        return rtrim($base, '/');
    }

    private function mediaPath(string $url): string
    {
        $prefix = $this->mediaBaseUrl().'/';
        if (! str_starts_with($url, $prefix) || strlen($url) === strlen($prefix) || strlen(substr($url, strlen($prefix))) > 1600) {
            throw new TextoSendFailedException('Conversations media must use the configured MMS template base URL; the package will not rewrite the host or discard attachments.');
        }

        return substr($url, strlen($prefix));
    }

    protected function addParticipantSilently(string $conversationSid, string $address, string $proxy): void
    {
        try {
            $this->conversationsApi->addParticipant($conversationSid, $address, $proxy);
        } catch (TwilioApiException $e) {
            if ((int) ($e->twilioCode ?? 0) === 50416) {
                Log::debug('Participant already exists in conversation', ['conversation_sid' => $conversationSid, 'address' => $address]);
            } else {
                Log::warning('Failed to add participant (non-fatal)', ['conversation_sid' => $conversationSid, 'address' => $address, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Unexpected participant add error (non-fatal)', ['conversation_sid' => $conversationSid, 'address' => $address, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Attempt to parse a Twilio Conversation SID (CH...) from a duplicate participant error.
     *
     * Twilio sometimes includes the existing Conversation SID in the error message
     * when attempting to add a participant that already exists.
     *
     * @param  string  $errorMessage  The error message from Twilio API
     * @return string|null The parsed conversation SID or null if not found
     */
    protected function parseConversationSidFromError(string $errorMessage): ?string
    {
        if (preg_match('/(CH[a-zA-Z0-9]{32})/', $errorMessage, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Create or update the webhook matching this URL, preserving other destinations.
     *
     * @param  string  $conversationSid  The conversation SID
     * @param  string  $webhookUrl  The webhook URL to attach
     * @return string|null The webhook SID or null on failure
     */
    protected function attachConversationWebhook(string $conversationSid, string $webhookUrl): ?string
    {
        if (! str_starts_with($webhookUrl, 'http')) {
            $appUrl = config('app.url');
            if ($appUrl) {
                $webhookUrl = rtrim($appUrl, '/').'/'.ltrim($webhookUrl, '/');
            }
        }
        try {
            $created = $this->conversationsApi->attachWebhook($conversationSid, $webhookUrl);
            $sid = $created['sid'] ?? null;
            if ($sid) {
                Log::info('Attached Twilio Conversation webhook', ['conversation_sid' => $conversationSid, 'webhook_sid' => $sid, 'url' => $webhookUrl]);
            }

            return $sid;
        } catch (TwilioApiException $e) {
            Log::warning('Failed to attach Twilio Conversation webhook (non-fatal)', ['conversation_sid' => $conversationSid, 'url' => $webhookUrl, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected error attaching Conversation webhook (non-fatal)', ['conversation_sid' => $conversationSid, 'url' => $webhookUrl, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch the latest status for a Twilio message.
     *
     * When messages are sent via Conversations API, we must use the Conversations API
     * for status lookup since the classic Messages API may not return the message SID.
     *
     * @param  string  $providerMessageId  The Twilio Message SID
     * @param  mixed  ...$context  Optional extra context (e.g. conversation SID)
     * @return MessageStatus|null The current message status or null if not found
     */
    public function fetchStatus(string $providerMessageId, mixed ...$context): ?MessageStatus
    {
        $conversationSid = null;
        if (! empty($context)) {
            $candidate = $context[0] ?? null;
            $conversationSid = is_string($candidate) ? $candidate : null;
        }
        if ($conversationSid !== null) {
            try {
                $message = $this->conversationsApi->fetchConversationMessage($conversationSid, $providerMessageId);
                $raw = $message['status'] ?? null;
                if (is_string($raw) && $raw !== '') {
                    return StatusMapper::map(Driver::Twilio, $raw);
                }
                $delivery = $message['delivery'] ?? null;
                if (! is_array($delivery)) {
                    return null;
                }
                foreach (['read' => MessageStatus::Read, 'delivered' => MessageStatus::Delivered] as $key => $status) {
                    if (in_array($delivery[$key] ?? null, ['some', 'all'], true)) {
                        return $status;
                    }
                }
                if (($delivery['failed'] ?? null) === 'all') {
                    return MessageStatus::Failed;
                }
                if (($delivery['undelivered'] ?? null) === 'all') {
                    return MessageStatus::Undelivered;
                }
                if (($delivery['failed'] ?? null) === 'some' && ($delivery['undelivered'] ?? null) === 'some'
                    && $this->conversationsApi instanceof TwilioDeliveryReceiptsApiInterface) {
                    $receipts = $this->conversationsApi->fetchDeliveryReceipts($conversationSid, $providerMessageId);
                    $total = $delivery['total'] ?? null;
                    $allFailed = is_int($total) && $total > 0 && count($receipts) >= $total;
                    $hardFailure = false;
                    $delivered = false;
                    foreach ($receipts as $receipt) {
                        $status = $receipt['status'] ?? null;
                        if ($status === 'read') {
                            return MessageStatus::Read;
                        }
                        if ($status === 'delivered') {
                            $delivered = true;
                        }
                        if (! in_array($status, ['failed', 'undelivered'], true)) {
                            $allFailed = false;
                        }
                        if ($status === 'failed') {
                            $hardFailure = true;
                        }
                    }
                    if ($delivered) {
                        return MessageStatus::Delivered;
                    }
                    if ($allFailed) {
                        return $hardFailure ? MessageStatus::Failed : MessageStatus::Undelivered;
                    }
                }

                return MessageStatus::Sent;
            } catch (\Throwable $exception) {
                Log::warning('Twilio Conversations receipt lookup failed.', ['conversation_sid' => $conversationSid, 'message_sid' => $providerMessageId]);

                return null;
            }
        }
        if (str_starts_with($providerMessageId, 'IM')) {
            return null;
        }

        try {
            $message = $this->messagingApi->fetchMessage($providerMessageId);
            $raw = $message['status'] ?? null;
            if (! $raw) {
                return null;
            }

            return StatusMapper::map(Driver::Twilio, $raw, null);
        } catch (TwilioApiException $e) {
            Log::warning('Twilio fetchStatus (legacy) failed', ['sid' => $providerMessageId, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('Twilio fetchStatus (legacy unexpected) failed', ['sid' => $providerMessageId, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
