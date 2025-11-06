<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
use Awaisjameel\Texto\Support\TwilioContentService;
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\RestException as TwilioRestException;
use Twilio\Rest\Client as TwilioClient;

class TwilioSender implements MessageSenderInterface
{
    protected TwilioClient $client;

    protected ?\Twilio\Rest\Conversations\V1 $conversationsClient = null;

    protected ?string $smsTemplateSid = null;

    protected ?string $mmsTemplateSid = null;

    public function __construct(protected array $config)
    {
        $sid = $config['account_sid'] ?? null;
        $token = $config['auth_token'] ?? null;
        if (! $sid || ! $token) {
            throw new TextoSendFailedException('Twilio credentials missing.');
        }
        $this->client = new TwilioClient($sid, $token);
    }

    /**
     * Public send entry. If conversations disabled fall back to classic Messages API.
     *
     * @param  string[]  $mediaUrls
     */
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {

        $fromNumber = $from?->e164 ?? ($this->config['from_number'] ?? null);
        if (! $fromNumber) {
            throw new TextoSendFailedException('Twilio from number not configured.');
        }

        $useConversations = ($this->config['use_conversations'] ?? true) === true && $this->conversationsClient !== null;

        if ($useConversations) {
            return $this->sendViaConversations($to, $body, $fromNumber, $mediaUrls, $metadata);
        }
        // Legacy direct message path
        try {
            $message = Retry::exponential(function () use ($to, $fromNumber, $body, $mediaUrls) {
                return $this->client->messages->create($to->e164, [
                    'from' => $fromNumber,
                    'body' => $body,
                    ...(empty($mediaUrls) ? [] : ['mediaUrl' => $mediaUrls]),
                ]);
            }, (int) config('texto.retry.max_attempts', 3), (int) config('texto.retry.backoff_start_ms', 200));
        } catch (\Throwable $e) {
            Log::error('Texto Twilio (legacy) send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Twilio send failed: '.$e->getMessage());
        }

        $providerSid = $message->sid ?? ($message->messageSid ?? null);
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
     * Conversations + Content Templates send implementation.
     *
     * @param  string[]  $mediaUrls
     */
    protected function sendViaConversations(PhoneNumber $to, string $body, string $fromNumber, array $mediaUrls, array $metadata): SentMessageResult
    {

        if (($config['use_conversations'] ?? true) === true) {
            $this->conversationsClient = $this->client->conversations->v1;
            $this->InitializeTemplates();
        }

        // 1. Create a conversation (ephemeral per send initially)
        $prefix = $this->config['conversation_prefix'] ?? 'Texto';
        $friendlyName = $prefix.'-'.$to->e164.'-'.bin2hex(random_bytes(4));
        try {
            $newConversation = $this->conversationsClient->conversations->create(['FriendlyName' => $friendlyName]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Twilio Conversation', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Unable to create Twilio Conversation: '.$e->getMessage());
        }

        $conversationSid = $newConversation->sid ?? null;
        if (! $conversationSid) {
            throw new TextoSendFailedException('Twilio Conversation creation returned no SID.');
        }

        $reusedConversation = false;

        // 2. Attempt to add recipient participant. If duplicate (50416) is detected, try to parse existing conversation SID and reuse it.
        try {
            $this->conversationsClient
                ->conversations($conversationSid)
                ->participants
                ->create([
                    'messagingBindingAddress' => $to->e164,
                    'messagingBindingProxyAddress' => $fromNumber,
                ]);
        } catch (TwilioRestException $e) {
            if ($e->getCode() === 50416) { // Duplicate participant binding
                $existingSid = $this->parseConversationSidFromError($e->getMessage());
                if ($existingSid && $existingSid !== $conversationSid) {
                    // Delete the freshly created conversation (best-effort) and reuse existing
                    try {
                        $this->conversationsClient->conversations($conversationSid)->delete();
                        Log::debug('Deleted newly created Twilio Conversation after detecting existing participant.', ['conversation_sid' => $conversationSid]);
                    } catch (\Throwable $del) {
                        Log::debug('Failed to delete newly created Twilio Conversation after duplicate participant.', ['conversation_sid' => $conversationSid, 'error' => $del->getMessage()]);
                    }
                    $conversationSid = $existingSid;
                    $reusedConversation = true;
                    Log::info('Reusing existing Twilio Conversation for participant.', ['conversation_sid' => $conversationSid, 'to' => $to->e164]);
                } else {
                    Log::warning('Duplicate participant error without parsable conversation SID; continuing with new conversation.', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                }
            } else {
                Log::error('Failed to add initial participant to Twilio Conversation', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Unable to add participant to Twilio Conversation: '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('Unexpected participant add exception', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
            throw new TextoSendFailedException('Unable to add participant to Twilio Conversation: '.$e->getMessage());
        }

        // 2b. Ensure system/from participant exists (duplicate tolerated silently)
        $this->addParticipantSilently($conversationSid, $fromNumber, $fromNumber);

        // 2c. Optionally attach webhook if URL provided (metadata override wins over config)
        $webhookUrl = $metadata['webhook_url'] ?? ($this->config['conversation_webhook_url'] ?? null);
        $webhookSid = null;
        if ($webhookUrl) {
            $webhookSid = $this->attachConversationWebhook($conversationSid, $webhookUrl);
        }

        // 3. Prepare content template usage
        $mediaUrl = $mediaUrls[0] ?? null;
        $contentVariables = $this->prepareContentVariables($body, $mediaUrl);
        $contentSid = $mediaUrl ? $this->mmsTemplateSid : $this->smsTemplateSid;

        $useContentTemplate = $contentSid !== null;
        $messageData = [
            'author' => $fromNumber,
            ...($useContentTemplate ? [
                'contentSid' => $contentSid,
                'contentVariables' => json_encode($contentVariables),
            ] : ['body' => $body]),
        ];

        // 4. Send message within conversation (with fallback if template fails)
        try {
            $sent = $this->conversationsClient->conversations($conversationSid)->messages->create($messageData);
        } catch (TwilioRestException $e) {
            if ($useContentTemplate && $e->getCode() === 20404) { // Not Found or template mismatch fallback
                Log::warning('Template send failed, retrying with body fallback.', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                $messageDataFallback = ['author' => $fromNumber, 'body' => $body];
                $sent = $this->conversationsClient->conversations($conversationSid)->messages->create($messageDataFallback);
            } else {
                Log::error('Twilio Conversation message send failed', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Twilio Conversation send failed: '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('Unexpected Twilio Conversation send exception', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
            throw new TextoSendFailedException('Twilio Conversation send failed: '.$e->getMessage());
        }

        $providerSid = $sent->sid ?? ($sent->messageSid ?? null);
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

    /** Initialize (or reuse configured) template SIDs */
    protected function InitializeTemplates(): void
    {
        $this->smsTemplateSid = $this->config['sms_template_sid'] ?? null;
        $this->mmsTemplateSid = $this->config['mms_template_sid'] ?? null;
        if ($this->smsTemplateSid && $this->mmsTemplateSid) {
            return; // explicitly provided
        }

        $friendlySms = $this->config['sms_template_friendly_name'] ?? 'texto_sms_template';
        $friendlyMms = $this->config['mms_template_friendly_name'] ?? 'texto_mms_template';

        $content = new TwilioContentService(
            $this->config['account_sid'],
            $this->config['auth_token']
        );

        try {
            if (! $this->smsTemplateSid) {
                $this->smsTemplateSid = $content->ensureTemplate($friendlySms, fn () => $this->defaultSmsTemplateDefinition($friendlySms));
            }
            if (! $this->mmsTemplateSid) {
                $this->mmsTemplateSid = $content->ensureTemplate($friendlyMms, fn () => $this->defaultMmsTemplateDefinition($friendlyMms));
            }
        } catch (\Throwable $e) {
            // Non-fatal: we can still fallback to body-only sending.
            Log::warning('Twilio Content template Initialization failed; will fallback to body sends.', ['error' => $e->getMessage()]);
            $this->smsTemplateSid = null;
            $this->mmsTemplateSid = null;
        }
    }

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
                    'media' => ['https://placehold.co/{{media_path}}'],
                ],
            ],
        ];
    }

    /** Split body into up to 5 x 100-char segments + optional media path variable */
    protected function prepareContentVariables(string $body, ?string $mediaUrl = null): array
    {
        $parts = str_split($body, 100);
        $vars = [];
        for ($i = 1; $i <= 5; $i++) {
            $vars['message_body_'.$i] = $parts[$i - 1] ?? '';
        }
        if ($mediaUrl) {
            $path = parse_url($mediaUrl, PHP_URL_PATH) ?: '';
            $path = ltrim($path, '/');
            $vars['media_path'] = rawurlencode($path);
        }

        return $vars;
    }

    protected function addParticipantSilently(string $conversationSid, string $address, string $proxy): void
    {
        try {
            $this->conversationsClient
                ->conversations($conversationSid)
                ->participants
                ->create([
                    'messagingBindingAddress' => $address,
                    'messagingBindingProxyAddress' => $proxy,
                ]);
        } catch (TwilioRestException $e) {
            if ($e->getCode() === 50416) { // Duplicate participant; ignore
                Log::debug('Participant already exists in conversation', ['conversation_sid' => $conversationSid, 'address' => $address]);
            } else {
                Log::warning('Failed to add participant (non-fatal)', ['conversation_sid' => $conversationSid, 'address' => $address, 'error' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Unexpected participant add error (non-fatal)', ['conversation_sid' => $conversationSid, 'address' => $address, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Attempt to parse a Twilio Conversation SID (CH...) out of a duplicate participant error message.
     * Twilio sometimes includes the existing Conversation SID in the error string.
     */
    protected function parseConversationSidFromError(string $errorMessage): ?string
    {
        if (preg_match('/(CH[a-zA-Z0-9]{32})/', $errorMessage, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Attaches (replaces) a webhook for events we care about. Returns webhook SID or null.
     */
    protected function attachConversationWebhook(string $conversationSid, string $webhookUrl): ?string
    {
        // Normalize URL (allow relative path by converting to app URL base if needed)
        if (! str_starts_with($webhookUrl, 'http')) {
            $appUrl = config('app.url');
            if ($appUrl) {
                $webhookUrl = rtrim($appUrl, '/').'/'.ltrim($webhookUrl, '/');
            }
        }

        try {
            $existing = $this->conversationsClient->conversations($conversationSid)->webhooks->read(20);
            foreach ($existing as $wh) {
                try {
                    $wh->delete();
                } catch (\Throwable $inner) {
                    Log::debug('Failed to delete existing conversation webhook (continuing)', ['conversation_sid' => $conversationSid, 'webhook_sid' => $wh->sid, 'error' => $inner->getMessage()]);
                }
            }
            $created = $this->conversationsClient->conversations($conversationSid)->webhooks->create('webhook', [
                'configurationMethod' => 'POST',
                'configurationFilters' => ['onMessageAdded', 'onMessageUpdated'],
                'configurationTriggers' => ['onMessageAdded', 'onMessageUpdated'],
                'configurationUrl' => $webhookUrl,
                'configurationReplayAfter' => 0,
            ]);
            Log::info('Attached Twilio Conversation webhook', ['conversation_sid' => $conversationSid, 'webhook_sid' => $created->sid, 'url' => $webhookUrl]);

            return $created->sid ?? null;
        } catch (TwilioRestException $e) {
            Log::warning('Failed to attach Twilio Conversation webhook (non-fatal)', ['conversation_sid' => $conversationSid, 'url' => $webhookUrl, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('Unexpected error attaching Conversation webhook (non-fatal)', ['conversation_sid' => $conversationSid, 'url' => $webhookUrl, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch latest status for a Twilio message.
     * When the message was sent inside a Conversation we must use the Conversations API
     * because the classic Messages API lookup may not return the message SID.
     *
     * @param  string  $providerMessageId  The Twilio Message SID (for legacy or conversation message).
     * @param  string|null  $conversationSid  Optional Conversation SID if the message was sent via Conversations.
     */
    public function fetchStatus(string $providerMessageId, ?string $conversationSid = null): ?MessageStatus
    {
        $useConversations = ($this->config['use_conversations'] ?? true) === true;

        // Attempt conversation fetch first if we have a conversation SID.
        if ($useConversations && $conversationSid) {
            try {
                if ($this->conversationsClient === null) {
                    // Lazy init to avoid overhead when not sending in this lifecycle.
                    $this->conversationsClient = $this->client->conversations->v1;
                }
                $message = $this->conversationsClient
                    ->conversations($conversationSid)
                    ->messages($providerMessageId)
                    ->fetch();

                // Log::info('Texto Twilio fetchStatus (conversation) response received', [
                //     'conversation_sid' => $conversationSid,
                //     'message_sid' => $providerMessageId,
                //     'response' => $message,
                // ]);

                // Conversation message status fields can vary; attempt several common keys.
                $raw = null;
                // 1. Direct status property (rare)
                if (isset($message->status)) {
                    $raw = $message->status;
                }
                // 2. Delivery sub-object (deliveryStatus or status/state)
                if (! $raw && isset($message->delivery)) {
                    $delivery = $message->delivery;
                    if (is_object($delivery)) {
                        $raw = $delivery->deliveryStatus ?? $delivery->status ?? $delivery->state ?? null;
                    } elseif (is_array($delivery)) {
                        $raw = $delivery['deliveryStatus'] ?? $delivery['status'] ?? $delivery['state'] ?? null;
                    }
                }
                // 3. Fallback to delivery receipts for the first participant (if available)
                if (! $raw && isset($message->delivery)) {
                    $deliveryAny = $message->delivery;
                    $receipts = null;
                    if (is_object($deliveryAny)) {
                        $receipts = $deliveryAny->receipts ?? null;
                    } elseif (is_array($deliveryAny)) {
                        $receipts = $deliveryAny['receipts'] ?? null;
                    }
                    if ($receipts && is_iterable($receipts)) {
                        foreach ($receipts as $r) {
                            if (is_object($r) && isset($r->status)) {
                                $raw = $r->status;
                                break;
                            } elseif (is_array($r) && isset($r['status'])) {
                                $raw = $r['status'];
                                break;
                            }
                        }
                    }
                }

                if ($raw) {
                    // Log::info('Texto Twilio fetchStatus (conversation) parsed', [
                    //     'conversation_sid' => $conversationSid,
                    //     'message_sid' => $providerMessageId,
                    //     'raw_status' => $raw,
                    // ]);

                    return StatusMapper::map(Driver::Twilio, $raw, null);
                }

                // If we couldn't derive a status from the conversation message, fall through to legacy path.
                // Log::debug('Texto Twilio fetchStatus (conversation) no status found, falling back to Messages API', [
                //     'conversation_sid' => $conversationSid,
                //     'message_sid' => $providerMessageId,
                // ]);
            } catch (\Throwable $e) {
                // Conversation fetch failed; log and fall back to legacy Messages API.
                Log::warning('Twilio fetchStatus (conversation) failed, falling back', [
                    'conversation_sid' => $conversationSid,
                    'message_sid' => $providerMessageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Legacy/direct Messages API fetch path.
        try {
            $message = $this->client->messages($providerMessageId)->fetch();
            // Log::info('Texto Twilio fetchStatus (legacy) response received', ['id' => $providerMessageId]);
            $raw = $message->status ?? null; // queued, accepted, sending, sent, delivered, failed, undelivered
            if (! $raw) {
                return null;
            }
            // Log::info('Texto Twilio fetchStatus (legacy) parsed', ['id' => $providerMessageId, 'raw_status' => $raw]);

            return StatusMapper::map(Driver::Twilio, $raw, null);
        } catch (\Throwable $e) {
            Log::warning('Twilio fetchStatus (legacy) failed', ['sid' => $providerMessageId, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
