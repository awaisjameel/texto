<?php

declare(strict_types=1);

namespace Awaisjameel\Texto\Support;

use Awaisjameel\Texto\Contracts\TwilioMessagingApiInterface;
use Awaisjameel\Texto\Enums\Driver;
use Illuminate\Http\Client\PendingRequest;

class TwilioMessagingApi implements TwilioMessagingApiInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param array<string, mixed>|null $config */
    public function __construct(protected string $accountSid, protected string $authToken, ?array $config = null)
    {
        if ($accountSid === '' || $authToken === '') {
            throw new \InvalidArgumentException('TwilioMessagingApi requires non-empty credentials.');
        }
        $this->config = array_replace($config ?? Configuration::forDriver(Driver::Twilio), ['account_sid' => $accountSid, 'auth_token' => $authToken]);
    }

    protected function http(): PendingRequest
    {
        return ProviderHttp::twilio('messaging', $this->config);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $to, string $from, ?string $body, array $mediaUrls = [], array $options = []): array
    {
        $endpoint = '/Accounts/'.rawurlencode($this->accountSid).'/Messages.json';
        $payload = [
            'To' => $to,
            'From' => $from,
        ] + ($body !== null ? ['Body' => $body] : []);
        foreach ($options as $k => $v) {
            $payload[$k] = $v;
        }

        // Built manually rather than via Http::asForm so MediaUrl can repeat across media items.
        $form = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        foreach ($mediaUrls as $m) {
            $form .= '&'.'MediaUrl='.rawurlencode($m);
        }

        $response = $this->http()
            ->withBody($form, 'application/x-www-form-urlencoded')
            ->post($endpoint);

        return TwilioResponse::read($response, 'sendMessage', requiredField: 'sid');
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchMessage(string $messageSid): array
    {
        $endpoint = '/Accounts/'.rawurlencode($this->accountSid).'/Messages/'.rawurlencode($messageSid).'.json';
        $response = $this->http()->get($endpoint);

        return TwilioResponse::read($response, 'fetchMessage', ['sid' => $messageSid]);
    }
}
