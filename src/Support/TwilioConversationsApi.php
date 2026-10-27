<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface;
use Awaisjameel\Texto\Contracts\TwilioDeliveryReceiptsApiInterface;
use Awaisjameel\Texto\Enums\Driver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

class TwilioConversationsApi implements TwilioConversationsApiInterface, TwilioDeliveryReceiptsApiInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(protected string $accountSid, protected string $authToken, ?array $config = null)
    {
        if ($accountSid === '' || $authToken === '') {
            throw new \InvalidArgumentException('TwilioConversationsApi requires non-empty credentials.');
        }
        $this->config = array_replace($config ?? Configuration::forDriver(Driver::Twilio), ['account_sid' => $accountSid, 'auth_token' => $authToken]);
    }

    protected function http(): PendingRequest
    {
        return ProviderHttp::twilio('conversations', $this->config);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createConversation(string $friendlyName, array $params = []): array
    {
        $payload = ['FriendlyName' => $friendlyName] + $params;
        $resp = $this->http()->post('/Conversations', $payload);

        return TwilioResponse::read($resp, 'createConversation', requiredField: 'sid');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function addParticipant(string $conversationSid, string $address, string $proxyAddress, array $params = []): array
    {
        $payload = [
            'MessagingBinding.Address' => $address,
            'MessagingBinding.ProxyAddress' => $proxyAddress,
        ] + $params;
        $resp = $this->http()->post('/Conversations/'.rawurlencode($conversationSid).'/Participants', $payload);

        return TwilioResponse::read($resp, 'addParticipant', ['conversation_sid' => $conversationSid]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendConversationMessage(string $conversationSid, array $payload): array
    {
        $resp = $this->http()->post('/Conversations/'.rawurlencode($conversationSid).'/Messages', $payload);

        return TwilioResponse::read($resp, 'sendConversationMessage', ['conversation_sid' => $conversationSid], requiredField: 'sid');
    }

    /**
     * @param  list<string>  $filters
     * @param  list<string>  $triggers
     * @return array<string, mixed>|null
     */
    public function attachWebhook(string $conversationSid, string $url, array $filters = ['onMessageAdded', 'onMessageUpdated'], array $triggers = []): ?array
    {
        $endpoint = '/Conversations/'.rawurlencode($conversationSid).'/Webhooks';
        $matchingSid = null;
        foreach (TwilioPaginator::records($this->http(), ProviderHttp::twilioBaseUrl('conversations', $this->config), $endpoint, 'webhooks') as $webhook) {
            $configuration = is_array($webhook['configuration'] ?? null) ? $webhook['configuration'] : [];
            if (($webhook['target'] ?? null) === 'webhook' && ($configuration['url'] ?? null) === $url) {
                $matchingSid = JsonResponse::requiredText($webhook, 'sid');
                break;
            }
        }
        $payload = [
            'Configuration.Url' => $url,
            'Configuration.Method' => 'POST',
        ];
        if ($matchingSid === null) {
            $payload['Target'] = 'webhook';
        } else {
            $endpoint .= '/'.rawurlencode($matchingSid);
        }
        // Built manually rather than via Http::asForm so the array parameters repeat per item
        // (Configuration.Filters=a&Configuration.Filters=b) — Twilio does not honor
        // comma-joined values, which would leave the webhook without working event filters.
        $form = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        foreach ($filters as $filter) {
            $form .= '&Configuration.Filters='.rawurlencode($filter);
        }
        foreach ($triggers as $trigger) {
            $form .= '&Configuration.Triggers='.rawurlencode($trigger);
        }
        $resp = $this->http()
            ->withBody($form, 'application/x-www-form-urlencoded')
            ->post($endpoint);
        $data = TwilioResponse::read($resp, 'attachWebhook', ['conversation_sid' => $conversationSid], requiredField: 'sid');

        return $data ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchConversationMessage(string $conversationSid, string $messageSid): array
    {
        $resp = $this->http()->get('/Conversations/'.rawurlencode($conversationSid).'/Messages/'.rawurlencode($messageSid));

        return TwilioResponse::read($resp, 'fetchConversationMessage', ['conversation_sid' => $conversationSid, 'message_sid' => $messageSid]);
    }

    public function deleteConversation(string $conversationSid): bool
    {
        $resp = $this->http()->delete('/Conversations/'.rawurlencode($conversationSid));
        if ($resp->successful() || $resp->status() === 204) {
            return true;
        }
        Log::debug('Failed to delete conversation', ['conversation_sid' => $conversationSid, 'status' => $resp->status()]);

        return false;
    }

    public function fetchDeliveryReceipts(string $conversationSid, string $messageSid): array
    {
        return iterator_to_array(TwilioPaginator::records(
            $this->http(), ProviderHttp::twilioBaseUrl('conversations', $this->config),
            '/Conversations/'.rawurlencode($conversationSid).'/Messages/'.rawurlencode($messageSid).'/Receipts',
            'delivery_receipts',
        ), false);
    }
}
