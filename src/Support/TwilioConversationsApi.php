<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioConversationsApiInterface;
use Awaisjameel\Texto\Exceptions\TwilioApiAuthException;
use Awaisjameel\Texto\Exceptions\TwilioApiException;
use Awaisjameel\Texto\Exceptions\TwilioApiNotFoundException;
use Awaisjameel\Texto\Exceptions\TwilioApiRateLimitException;
use Awaisjameel\Texto\Exceptions\TwilioApiValidationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwilioConversationsApi implements TwilioConversationsApiInterface
{
    public function __construct(protected string $accountSid, protected string $authToken)
    {
        if ($accountSid === '' || $authToken === '') {
            throw new \InvalidArgumentException('TwilioConversationsApi requires non-empty credentials.');
        }
    }

    protected function http(): PendingRequest
    {
        return Http::twilio('conversations')->withBasicAuth($this->accountSid, $this->authToken);
    }

    public function createConversation(string $friendlyName, array $params = []): array
    {
        $payload = ['FriendlyName' => $friendlyName] + $params;
        $resp = $this->http()->post('/Conversations', $payload);

        return $this->handle($resp, 'createConversation');
    }

    public function addParticipant(string $conversationSid, string $address, string $proxyAddress, array $params = []): array
    {
        $payload = [
            'MessagingBinding.Address' => $address,
            'MessagingBinding.ProxyAddress' => $proxyAddress,
        ] + $params;
        $resp = $this->http()->post('/Conversations/'.$conversationSid.'/Participants', $payload);

        return $this->handle($resp, 'addParticipant', ['conversation_sid' => $conversationSid]);
    }

    public function sendConversationMessage(string $conversationSid, array $payload): array
    {
        $resp = $this->http()->post('/Conversations/'.$conversationSid.'/Messages', $payload);

        return $this->handle($resp, 'sendConversationMessage', ['conversation_sid' => $conversationSid]);
    }

    public function attachWebhook(string $conversationSid, string $url, array $filters = ['onMessageAdded', 'onMessageUpdated'], array $triggers = []): ?array
    {
        // Delete existing webhooks first (best-effort)
        $existing = $this->http()->get('/Conversations/'.$conversationSid.'/Webhooks');
        if ($existing->successful()) {
            $items = $existing->json('webhooks') ?? [];
            foreach ($items as $w) {
                $sid = $w['sid'] ?? null;
                if ($sid) {
                    $this->http()->delete('/Conversations/'.$conversationSid.'/Webhooks/'.$sid);
                }
            }
        }
        $payload = [
            'Target' => 'webhook',
            'Configuration.Url' => $url,
            'Configuration.Method' => 'POST',
            'Configuration.ReplayAfter' => 0,
        ];
        // Built manually rather than via Http::asForm so the array parameters repeat per item
        // (Configuration.Filters=a&Configuration.Filters=b) — Twilio does not honor
        // comma-joined values, which would leave the webhook without working event filters.
        $form = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        foreach ($filters as $filter) {
            $form .= '&Configuration.Filters='.rawurlencode($filter);
        }
        foreach (($triggers ?: $filters) as $trigger) {
            $form .= '&Configuration.Triggers='.rawurlencode($trigger);
        }
        $resp = $this->http()
            ->withBody($form, 'application/x-www-form-urlencoded')
            ->post('/Conversations/'.$conversationSid.'/Webhooks');
        $data = $this->handle($resp, 'attachWebhook', ['conversation_sid' => $conversationSid]);

        return $data ?: null;
    }

    public function fetchConversationMessage(string $conversationSid, string $messageSid): array
    {
        $resp = $this->http()->get('/Conversations/'.$conversationSid.'/Messages/'.$messageSid);

        return $this->handle($resp, 'fetchConversationMessage', ['conversation_sid' => $conversationSid, 'message_sid' => $messageSid]);
    }

    public function deleteConversation(string $conversationSid): bool
    {
        $resp = $this->http()->delete('/Conversations/'.$conversationSid);
        if ($resp->successful() || $resp->status() === 204) {
            return true;
        }
        Log::debug('Failed to delete conversation', ['conversation_sid' => $conversationSid, 'status' => $resp->status()]);

        return false;
    }

    protected function handle($response, string $action, array $ctx = []): array
    {
        if ($response->successful()) {
            return $response->json();
        }
        $status = $response->status();
        $body = $response->json() ?? [];
        $code = $body['code'] ?? null;
        $message = $body['message'] ?? 'Twilio Conversations API error';
        $exContext = $ctx + ['status' => $status, 'twilio_code' => $code, 'action' => $action, 'body' => $body];
        Log::warning('Twilio Conversations API error', $exContext);
        if ($status === 401 || $status === 403) {
            throw new TwilioApiAuthException($message, $status, (string) $code, $exContext);
        }
        if ($status === 404) {
            throw new TwilioApiNotFoundException($message, $status, (string) $code, $exContext);
        }
        if ($status === 429) {
            throw new TwilioApiRateLimitException($message, $status, (string) $code, $exContext);
        }
        if ($status === 400) {
            throw new TwilioApiValidationException($message, $status, (string) $code, $exContext);
        }
        throw new TwilioApiException($message, $status, (string) $code, $exContext);
    }
}
