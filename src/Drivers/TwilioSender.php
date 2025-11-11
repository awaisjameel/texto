<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Drivers;

use Awaisjameel\Texto\Contracts\MessageSenderInterface;
use Awaisjameel\Texto\Contracts\PollableMessageSenderInterface;
use Awaisjameel\Texto\Enums\Direction;
use Awaisjameel\Texto\Enums\Driver;
use Awaisjameel\Texto\Enums\MessageStatus;
use Awaisjameel\Texto\Exceptions\TextoSendFailedException;
use Awaisjameel\Texto\Support\Retry;
use Awaisjameel\Texto\Support\StatusMapper;
// Deprecated SDK-based content service import removed; using HTTP adapter
use Awaisjameel\Texto\ValueObjects\PhoneNumber;
use Awaisjameel\Texto\ValueObjects\SentMessageResult;
use Illuminate\Support\Facades\Log;
use Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface;
use Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface;
use Awaisjameel\Texto\Contracts\TwilioContentApiInterface;
use Awaisjameel\Texto\Exceptions\TwilioApiException;

class TwilioSender implements MessageSenderInterface, PollableMessageSenderInterface
{
    protected TwilioMessagingApiInterface $messagingApi;

    protected TwilioConversationsApiInterface $conversationsApi;
    protected TwilioContentApiInterface $contentApi;

    protected ?string $smsTemplateSid = null;

    protected ?string $mmsTemplateSid = null;

    public function __construct(protected array $config)
    {
        $sid = $config['account_sid'] ?? null;
        $token = $config['auth_token'] ?? null;
        if (!$sid || !$token) {
            throw new TextoSendFailedException('Twilio credentials missing.');
        }
        // Resolve adapters from container if available; fallback to manual instantiation.
        $this->messagingApi = app()->bound(TwilioMessagingApiInterface::class)
            ? app(TwilioMessagingApiInterface::class)
            : new \Awaisjameel\Texto\Support\TwilioMessagingApi($sid, $token);
        $this->conversationsApi = app()->bound(TwilioConversationsApiInterface::class)
            ? app(TwilioConversationsApiInterface::class)
            : new \Awaisjameel\Texto\Support\TwilioConversationsApi($sid, $token);
        $this->contentApi = app()->bound(TwilioContentApiInterface::class)
            ? app(TwilioContentApiInterface::class)
            : new \Awaisjameel\Texto\Support\TwilioContentApi($sid, $token);
    }

    /**
     * Send an SMS/MMS message via Twilio.
     *
     * Supports both Conversations API (with templates) and classic Messages API.
     *
     * @param  PhoneNumber  $to  Recipient phone number
     * @param  string  $body  Message body text
     * @param  PhoneNumber|null  $from  Sender phone number
     * @param  string[]  $mediaUrls  Array of media URLs for MMS
     * @param  array<string, mixed>  $metadata  Additional metadata
     *
     * @throws TextoSendFailedException
     */
    public function send(PhoneNumber $to, string $body, ?PhoneNumber $from = null, array $mediaUrls = [], array $metadata = []): SentMessageResult
    {

        $fromNumber = $from?->e164 ?? ($this->config['from_number'] ?? null);
        if (!$fromNumber) {
            throw new TextoSendFailedException('Twilio from number not configured.');
        }

        $useConversations = ($this->config['use_conversations'] ?? true) === true;

        if ($useConversations) {
            return $this->sendViaConversations($to, $body, $fromNumber, $mediaUrls, $metadata);
        }
        // Direct Messages API path via adapter
        try {
            $raw = Retry::exponential(function () use ($to, $fromNumber, $body, $mediaUrls) {
                return $this->messagingApi->sendMessage(
                    $to->e164,
                    $fromNumber,
                    $body,
                    $mediaUrls,
                    []
                );
            }, (int) config('texto.retry.max_attempts', 3), (int) config('texto.retry.backoff_start_ms', 200));
        } catch (TwilioApiException $e) {
            Log::error('Texto Twilio (legacy REST) send failed', ['error' => $e->getMessage(), 'status' => $e->status, 'code' => $e->twilioCode]);
            throw new TextoSendFailedException('Twilio send failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Texto Twilio (legacy REST unexpected) send failed', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Twilio send failed: ' . $e->getMessage());
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

        $this->initializeTemplates();

        // 1. Create a conversation (ephemeral per send initially)
        $prefix = $this->config['conversation_prefix'] ?? 'Texto';
        $friendlyName = $prefix . '-' . $to->e164 . '-' . bin2hex(random_bytes(4));
        try {
            $newConversation = $this->conversationsApi->createConversation($friendlyName);
        } catch (TwilioApiException $e) {
            Log::error('Failed to create Twilio Conversation', ['error' => $e->getMessage()]);
            throw new TextoSendFailedException('Unable to create Twilio Conversation: ' . $e->getMessage());
        }

        $conversationSid = $newConversation['sid'] ?? null;
        if (!$conversationSid) {
            throw new TextoSendFailedException('Twilio Conversation creation returned no SID.');
        }

        $reusedConversation = false;

        // 2. Attempt to add recipient participant. If duplicate (50416) is detected, try to parse existing conversation SID and reuse it.
        try {
            $this->conversationsApi->addParticipant($conversationSid, $to->e164, $fromNumber);
        } catch (TwilioApiException $e) {
            // Twilio duplicate participant error code is 50416 (reported in SDK); check body code
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
                Log::error('Failed to add initial participant to Twilio Conversation', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Unable to add participant to Twilio Conversation: ' . $e->getMessage());
            }
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
            'Author' => $fromNumber,
            ...($useContentTemplate ? [
                'ContentSid' => $contentSid,
                'ContentVariables' => json_encode($contentVariables),
            ] : ['Body' => $body]),
        ];

        // 4. Send message within conversation (with fallback if template fails)
        try {
            $sentRaw = $this->conversationsApi->sendConversationMessage($conversationSid, $messageData);
        } catch (TwilioApiException $e) {
            if ($useContentTemplate && $e->status === 404) { // fallback on template not found
                Log::warning('Template send failed, retrying with body fallback.', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                $messageDataFallback = ['Author' => $fromNumber, 'Body' => $body];
                $sentRaw = $this->conversationsApi->sendConversationMessage($conversationSid, $messageDataFallback);
            } else {
                Log::error('Twilio Conversation message send failed', ['conversation_sid' => $conversationSid, 'error' => $e->getMessage()]);
                throw new TextoSendFailedException('Twilio Conversation send failed: ' . $e->getMessage());
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

    /** Initialize (or reuse configured) template SIDs */
    protected function initializeTemplates(): void
    {
        $this->smsTemplateSid = $this->config['sms_template_sid'] ?? null;
        $this->mmsTemplateSid = $this->config['mms_template_sid'] ?? null;
        if ($this->smsTemplateSid && $this->mmsTemplateSid) {
            return; // explicitly provided
        }

        $friendlySms = $this->config['sms_template_friendly_name'] ?? 'texto_sms_template';
        $friendlyMms = $this->config['mms_template_friendly_name'] ?? 'texto_mms_template';

        $content = $this->contentApi;

        try {
            if (!$this->smsTemplateSid) {
                $this->smsTemplateSid = $content->ensureTemplate($friendlySms, fn() => $this->defaultSmsTemplateDefinition($friendlySms));
            }
            if (!$this->mmsTemplateSid) {
                $this->mmsTemplateSid = $content->ensureTemplate($friendlyMms, fn() => $this->defaultMmsTemplateDefinition($friendlyMms));
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
                    'media' => [env('APP_URL') . '/{{media_path}}'],
                ],
            ],
        ];
    }

    /**
     * Split message body into template variables (up to 5 segments of 100 chars each).
     *
     * @param  string  $body  Message body text
     * @param  string|null  $mediaUrl  Optional media URL for MMS templates
     * @return array<string, string> Template variables array
     */
    protected function prepareContentVariables(string $body, ?string $mediaUrl = null): array
    {
        $parts = str_split($body, 100);
        $vars = [];
        for ($i = 1; $i <= 5; $i++) {
            $vars['message_body_' . $i] = $parts[$i - 1] ?? '';
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
     * Attach or replace webhook configuration for a conversation.
     *
     * Removes existing webhooks and creates a new one for message events.
     *
     * @param  string  $conversationSid  The conversation SID
     * @param  string  $webhookUrl  The webhook URL to attach
     * @return string|null The webhook SID or null on failure
     */
    protected function attachConversationWebhook(string $conversationSid, string $webhookUrl): ?string
    {
        if (!str_starts_with($webhookUrl, 'http')) {
            $appUrl = config('app.url');
            if ($appUrl) {
                $webhookUrl = rtrim($appUrl, '/') . '/' . ltrim($webhookUrl, '/');
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
     * @param  string|null  $conversationSid  Optional Conversation SID for conversation messages
     * @return MessageStatus|null The current message status or null if not found
     */
    public function fetchStatus(string $providerMessageId, mixed ...$context): ?MessageStatus
    {
        $conversationSid = null;
        if (!empty($context)) {
            $candidate = $context[0] ?? null;
            $conversationSid = is_string($candidate) ? $candidate : null;
        }
        $useConversations = ($this->config['use_conversations'] ?? true) === true;

        // Attempt conversation fetch first if we have a conversation SID.
        if ($useConversations && $conversationSid) {
            try {
                $message = $this->conversationsApi->fetchConversationMessage($conversationSid, $providerMessageId);

                // Log::info('Texto Twilio fetchStatus (conversation) response received', [
                //     'conversation_sid' => $conversationSid,
                //     'message_sid' => $providerMessageId,
                //     'response' => $message,
                // ]);

                // Conversation message status fields can vary; attempt several common keys.
                $raw = null;
                // 1. Direct status property (rare)
                if (isset($message['status'])) {
                    $raw = $message['status'];
                }
                // 2. Delivery sub-object (deliveryStatus or status/state)
                if (!$raw && isset($message['delivery'])) {
                    $delivery = $message['delivery'];
                    if (is_array($delivery)) {
                        $raw = $delivery['deliveryStatus'] ?? $delivery['status'] ?? $delivery['state'] ?? null;
                    }
                }
                // 3. Fallback to delivery receipts for the first participant (if available)
                if (!$raw && isset($message['delivery'])) {
                    $receipts = $message['delivery']['receipts'] ?? null;
                    if ($receipts && is_iterable($receipts)) {
                        foreach ($receipts as $r) {
                            if (is_array($r) && isset($r['status'])) {
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
            $message = $this->messagingApi->fetchMessage($providerMessageId);
            $raw = $message['status'] ?? null;
            if (!$raw) {
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
